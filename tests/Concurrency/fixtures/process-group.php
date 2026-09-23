<?php

/**
 * Exec the given command as the leader of a new process group.
 *
 * Usage: php process-group.php <binary> [args...]
 *
 * The PID stays the same across pcntl_exec(), so the caller can kill the
 * whole group (the command and everything it forks) with posix_kill(-pid).
 */
posix_setpgid(0, 0);
pcntl_exec($argv[1], array_slice($argv, 2));

fwrite(STDERR, "pcntl_exec failed\n");
exit(1);
