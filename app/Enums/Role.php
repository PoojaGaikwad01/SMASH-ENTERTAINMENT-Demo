<?php

namespace App\Enums;

/**
 * admin = everything; outlet_manager = own outlet (incl. packages/areas/addons);
 * front_desk = own outlet enquiries + bookings only (no users / catalogue).
 */
enum Role: string
{
    case Admin = 'admin';
    case OutletManager = 'outlet_manager';
    case FrontDesk = 'front_desk';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::OutletManager => 'Outlet Manager',
            self::FrontDesk => 'Front Desk',
        };
    }

    /** Roles that belong to exactly one outlet (users.branch_id required). */
    public static function outletRoles(): array
    {
        return [self::OutletManager, self::FrontDesk];
    }

    public static function outletRoleValues(): array
    {
        return array_map(fn (self $r) => $r->value, self::outletRoles());
    }
}
