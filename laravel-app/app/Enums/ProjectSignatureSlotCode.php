<?php

namespace App\Enums;

enum ProjectSignatureSlotCode: string
{
    case ProjectProposer = 'project_proposer';
    case RelatedApprover = 'related_approver';
    case DeputyDirector = 'deputy_director';
    case Director = 'director';

    public function slotNo(): int
    {
        return match ($this) {
            self::ProjectProposer => 1,
            self::RelatedApprover => 2,
            self::DeputyDirector => 3,
            self::Director => 4,
        };
    }

    /** @return list<string> */
    public function eligibleRoleCodes(): array
    {
        return match ($this) {
            self::ProjectProposer, self::RelatedApprover => ['teacher', 'department_head', 'deputy_director', 'director'],
            self::DeputyDirector => ['deputy_director'],
            self::Director => ['director'],
        };
    }
}
