<?php

declare(strict_types=1);

namespace Sova\Authorization\Domain;

enum PermissionScope: string
{
    case System = 'SYSTEM';
    case Tenant = 'TENANT';
    case Project = 'PROJECT';
    case Workgroup = 'WORKGROUP';
}
