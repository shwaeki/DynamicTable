<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
}
