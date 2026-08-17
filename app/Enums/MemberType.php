<?php

namespace App\Enums;

enum MemberType: string
{
    case VISITOR = 'Visiteur';

    case CATECHUMEN = 'Catéchumène';

    case MEMBER = 'Membre';

    case LEADER = 'Responsable';

    case PASTOR = 'Pasteur';
}