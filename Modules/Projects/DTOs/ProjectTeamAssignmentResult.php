<?php

namespace Modules\Projects\DTOs;

use Modules\Teams\Models\Team;

final readonly class ProjectTeamAssignmentResult
{
    public function __construct(
        public Team $team,
        public bool $assigned,
    ) {}
}
