<?php

declare(strict_types=1);

use App\Mcp\Servers\OniServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('oni', OniServer::class);
