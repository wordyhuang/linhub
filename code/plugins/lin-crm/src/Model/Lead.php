<?php
declare(strict_types=1);

namespace Lg\Pack\LinCrm\Model;

class Lead extends LinCrmModel
{
    protected string $name = 'lead';
    protected string $codePrefix = 'XS'; // GR-1
}
