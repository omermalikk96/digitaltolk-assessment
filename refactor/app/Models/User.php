<?php

namespace App\Models;

class User
{
    public function isAdmin(): bool
    {
        return config('roles.ADMIN_ROLE_ID') == $this->user_type;
    }

    public function isSuperAdmin(): bool
    {
        return config('roles.SUPERADMIN_ROLE_ID') == $this->user_type;
    }

    public function isCustomer()
    {
        return config('roles.CUSTOMER_ROLE_ID') == $this->user_type;
    }
}
