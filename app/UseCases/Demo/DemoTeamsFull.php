<?php

namespace App\UseCases\Demo;

use RuntimeException;

/**
 * 存在するデモチームが上限に達した(AC-007-10)。
 */
final class DemoTeamsFull extends RuntimeException {}
