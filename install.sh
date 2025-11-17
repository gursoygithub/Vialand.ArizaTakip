#!/bin/bash

# Colors for better readability
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to handle errors
handle_error() {
    echo -e "${RED}Error: $1${NC}"
    exit 1
}

# Function to check if MySQL is ready
check_mysql_ready() {
    echo -e "${YELLOW}Checking if MySQL is ready...${NC}"

    local max_attempts=30
    local attempt=1
    local sleep_time=30

    while [ $attempt -le $max_attempts ]; do
        echo -e "${YELLOW}Attempt $attempt of $max_attempts...${NC}"

        if ./vendor/bin/sail exec mysql mysqladmin ping -h mysql -u root -ppassword --silent; then
            echo -e "${GREEN}MySQL is ready!${NC}"
            return 0
        fi

        echo -e "${YELLOW}MySQL not ready yet. Waiting...${NC}"
        sleep $sleep_time
        attempt=$((attempt + 1))
    done

    echo -e "${RED}MySQL did not become ready in time.${NC}"
    return 1
}

echo -e "${GREEN}Starting installation...${NC}"

# Remove .git directory
echo -e "${YELLOW}Removing .git directory...${NC}"
rm -rf .git
if [ $? -ne 0 ]; then
    handle_error "Failed to remove .git directory"
fi

# Copy .env.example to .env
echo -e "${YELLOW}Setting up environment variables...${NC}"
cp .env.example .env
if [ $? -ne 0 ]; then
    handle_error "Failed to create .env file"
fi

# Install dependencies
echo -e "${YELLOW}Installing PHP dependencies...${NC}"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
if [ $? -ne 0 ]; then
    handle_error "Composer installation failed"
fi

# Start the services
echo -e "${YELLOW}Starting Docker services...${NC}"
./vendor/bin/sail up -d
if [ $? -ne 0 ]; then
    handle_error "Failed to start Docker services"
fi

# Wait for MySQL to be ready
check_mysql_ready
if [ $? -ne 0 ]; then
    handle_error "MySQL did not become ready. Please check your Docker services."
fi

# Generate application key
echo -e "${YELLOW}Generating application key...${NC}"
./vendor/bin/sail artisan key:generate
if [ $? -ne 0 ]; then
    handle_error "Failed to generate application key"
fi

# Run migrations
echo -e "${YELLOW}Running migrations...${NC}"
./vendor/bin/sail artisan migrate
if [ $? -ne 0 ]; then
    handle_error "Failed to run migrations"
fi

# Run seeders
echo -e "${YELLOW}Seeding database...${NC}"
./vendor/bin/sail artisan db:seed
if [ $? -ne 0 ]; then
    handle_error "Failed to seed database"
fi

# Run Shield permissions
echo -e "${YELLOW}Running Shield permissions...${NC}"
./vendor/bin/sail artisan shield:generate --all
if [ $? -ne 0 ]; then
    handle_error "Failed to run Shield permissions"
fi

# Set Super Admin
echo -e "${YELLOW}Setting Super Admin...${NC}"
./vendor/bin/sail artisan shield:super-admin
if [ $? -ne 0 ]; then
    handle_error "Failed to set Super Admin"
fi

# Set proper permissions for storage and bootstrap/cache
echo -e "${YELLOW}Setting proper permissions...${NC}"
./vendor/bin/sail artisan storage:link
chmod -R 775 storage bootstrap/cache

echo -e "${GREEN}Installation completed successfully!${NC}"
echo -e "${GREEN}Your application is now ready to use.${NC}"
echo -e "${YELLOW}You can access it at: http://localhost${NC}"



