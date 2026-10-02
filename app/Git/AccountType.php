<?php

namespace App\Git;

enum AccountType: string
{
    case User = 'user';
    case Organization = 'organization';
}
