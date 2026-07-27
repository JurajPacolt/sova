<?php

declare(strict_types=1);

namespace Sova\Projects\Domain;

enum ProjectVisibility: string
{
    case Tenant = 'TENANT';
    case Private = 'PRIVATE';
}
