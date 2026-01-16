<?php

namespace App\Ldap;

use App\Models\User as EloquentUser;
use LdapRecord\Models\Model as LdapModel;

class AttributeHandler
{
    public function handle(LdapModel $ldapModel, EloquentUser $eloquentModel)
    {
        // LDAP gruplarını JSON olarak kaydet
        $memberOf = $ldapModel->getAttribute('memberof');
        if ($memberOf) {
            $eloquentModel->ldap_groups = json_encode($memberOf);
        }

        // Son senkronizasyon zamanı
        $eloquentModel->last_ldap_sync = now();

        // Panel user olarak işaretle (LDAP'tan gelen herkes panel'e erişebilir)
        $eloquentModel->panel_user = true;
    }
}
