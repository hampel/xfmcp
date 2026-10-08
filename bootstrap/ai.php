<?php

use App\Mcp\Servers\XenForoServer;
use Laravel\Mcp\Facades\Mcp;

// Local only: a stdio server started with `xfmcp mcp:start xenforo`. There is no
// Mcp::web() here and there must never be one - nothing in this tool listens on a port.
Mcp::local('xenforo', XenForoServer::class);
