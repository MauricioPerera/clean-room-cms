<?php
/**
 * Clean Room CMS - Hook System (Actions & Filters)
 *
 * Reimplementation of the WordPress hook pattern based on public API documentation.
 * Actions execute callbacks at specific points. Filters modify data through a callback chain.
 */

class CR_Hook {
    private array $callbacks = [];
    private array $iterations = [];
    private array $current_priority = [];
    private int $nesting_level = 0;
    private bool $doing_action = false;

    public function add_callback(callable|array|string $callback, int $priority, int $accepted_args): void {
        $id = $this->callback_id($callback);
        $this->callbacks[$priority][$id] = [
            'function'      => $callback,
            'accepted_args' => $accepted_args,
        ];
        ksort($this->callbacks, SORT_NUMERIC);
    }

    public function remove_callback(callable|array|string $callback, int $priority): bool {
        $id = $this->callback_id($callback);
        if (isset($this->callbacks[$priority][$id])) {
            unset($this->callbacks[$priority][$id]);
            if (empty($this->callbacks[$priority])) {
                unset($this->callbacks[$priority]);
            }
            return true;
        }
        return false;
    }

    public function has_callback(callable|array|string|false $callback = false): bool|int {
        if ($callback === false) {
            return !empty($this->callbacks);
        }
        $id = $this->callback_id($callback);
        foreach ($this->callbacks as $priority => $handlers) {
            if (isset($handlers[$id])) {
                return $priority;
            }
        }
        return false;
    }

    public function apply_filters(mixed $value, array $args): mixed {
        $this->iterations[$this->nesting_level] = array_keys($this->callbacks);
        $this->nesting_level++;

        $num_args = count($args);

        do {
            $this->current_priority[$this->nesting_level - 1] = current($this->iterations[$this->nesting_level - 1]);
            $priority = $this->current_priority[$this->nesting_level - 1];

            if ($priority === false) {
                break;
            }

            foreach ($this->callbacks[$priority] as $entry) {
                $the_args = $args;
                $the_args[0] = $value;

                if ($entry['accepted_args'] === 0) {
                    $value = call_user_func($entry['function']);
                } elseif ($entry['accepted_args'] >= $num_args) {
                    $value = call_user_func_array($entry['function'], $the_args);
                } else {
                    $value = call_user_func_array($entry['function'], array_slice($the_args, 0, $entry['accepted_args']));
                }
            }
        } while (next($this->iterations[$this->nesting_level - 1]) !== false);

        $this->nesting_level--;
        unset($this->iterations[$this->nesting_level]);
        unset($this->current_priority[$this->nesting_level]);

        return $value;
    }

    public function do_action(array $args): void {
        $this->doing_action = true;

        $this->iterations[$this->nesting_level] = array_keys($this->callbacks);
        $this->nesting_level++;

        do {
            $this->current_priority[$this->nesting_level - 1] = current($this->iterations[$this->nesting_level - 1]);
            $priority = $this->current_priority[$this->nesting_level - 1];

            if ($priority === false) {
                break;
            }

            foreach ($this->callbacks[$priority] as $entry) {
                if ($entry['accepted_args'] === 0) {
                    call_user_func($entry['function']);
                } elseif ($entry['accepted_args'] >= count($args)) {
                    call_user_func_array($entry['function'], $args);
                } else {
                    call_user_func_array($entry['function'], array_slice($args, 0, $entry['accepted_args']));
                }
            }
        } while (next($this->iterations[$this->nesting_level - 1]) !== false);

        $this->nesting_level--;
        unset($this->iterations[$this->nesting_level]);
        unset($this->current_priority[$this->nesting_level]);

        $this->doing_action = false;
    }

    public function is_doing(): bool {
        return $this->doing_action;
    }

    public function callback_count(): int {
        $count = 0;
        foreach ($this->callbacks as $handlers) {
            $count += count($handlers);
        }
        return $count;
    }

    private function callback_id(callable|array|string $callback): string {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                return spl_object_id($callback[0]) . '::' . $callback[1];
            }
            return $callback[0] . '::' . $callback[1];
        }
        if ($callback instanceof Closure) {
            return spl_object_id($callback) . '::closure';
        }
        return md5(serialize($callback));
    }
}

// Global hook registry
$cr_filters = [];
$cr_actions_run = [];
$cr_current_filter = [];

/**
 * Add a callback to a filter hook.
 */
function add_filter(string $hook_name, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool {
    global $cr_filters;

    if (!isset($cr_filters[$hook_name])) {
        $cr_filters[$hook_name] = new CR_Hook();
    }

    $cr_filters[$hook_name]->add_callback($callback, $priority, $accepted_args);
    return true;
}

/**
 * Add a callback to an action hook.
 */
function add_action(string $hook_name, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool {
    return add_filter($hook_name, $callback, $priority, $accepted_args);
}

/**
 * Apply all filter callbacks to a value.
 */
function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed {
    global $cr_filters, $cr_current_filter;

    $cr_current_filter[] = $hook_name;

    if (!isset($cr_filters[$hook_name])) {
        array_pop($cr_current_filter);
        return $value;
    }

    $all_args = array_merge([$value], $args);
    $filtered = $cr_filters[$hook_name]->apply_filters($value, $all_args);

    array_pop($cr_current_filter);
    return $filtered;
}

/**
 * Execute all action callbacks for a hook.
 */
function do_action(string $hook_name, mixed ...$args): void {
    global $cr_filters, $cr_actions_run, $cr_current_filter;

    if (!isset($cr_actions_run[$hook_name])) {
        $cr_actions_run[$hook_name] = 0;
    }
    $cr_actions_run[$hook_name]++;

    $cr_current_filter[] = $hook_name;

    if (isset($cr_filters[$hook_name])) {
        $cr_filters[$hook_name]->do_action($args);
    }

    array_pop($cr_current_filter);
}

/**
 * Remove a callback from a filter hook.
 */
function remove_filter(string $hook_name, callable|array|string $callback, int $priority = 10): bool {
    global $cr_filters;

    if (!isset($cr_filters[$hook_name])) {
        return false;
    }

    return $cr_filters[$hook_name]->remove_callback($callback, $priority);
}

/**
 * Remove a callback from an action hook.
 */
function remove_action(string $hook_name, callable|array|string $callback, int $priority = 10): bool {
    return remove_filter($hook_name, $callback, $priority);
}

/**
 * Check if a filter hook has a specific callback registered.
 */
function has_filter(string $hook_name, callable|array|string|false $callback = false): bool|int {
    global $cr_filters;

    if (!isset($cr_filters[$hook_name])) {
        return false;
    }

    return $cr_filters[$hook_name]->has_callback($callback);
}

/**
 * Check if an action hook has a specific callback registered.
 */
function has_action(string $hook_name, callable|array|string|false $callback = false): bool|int {
    return has_filter($hook_name, $callback);
}

/**
 * Return the number of times an action has been fired.
 */
function did_action(string $hook_name): int {
    global $cr_actions_run;
    return $cr_actions_run[$hook_name] ?? 0;
}

/**
 * Check if an action is currently being executed.
 */
function doing_action(?string $hook_name = null): bool {
    global $cr_current_filter, $cr_filters;

    if ($hook_name === null) {
        return !empty($cr_current_filter);
    }

    return in_array($hook_name, $cr_current_filter, true);
}

/**
 * Return the name of the hook currently being processed.
 */
function current_filter(): string|false {
    global $cr_current_filter;
    return end($cr_current_filter);
}

function doing_filter(?string $hook_name = null): bool {
    return doing_action($hook_name);
}
