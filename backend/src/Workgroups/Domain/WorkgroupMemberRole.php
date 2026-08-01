<?php

declare(strict_types=1);

namespace Sova\Workgroups\Domain;

enum WorkgroupMemberRole: string
{
    case Member = 'MEMBER';
    case Manager = 'MANAGER';
}
