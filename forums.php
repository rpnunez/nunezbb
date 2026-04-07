<?php

/**
 * forums.php
 *
 * Demonstrates a hierarchical category/forum system.
 *
 * Each forum entry in the $forums array has the following keys:
 *   - id          (int)    Unique identifier for the forum.
 *   - name        (string) Display name of the forum.
 *   - description (string) Short description of the forum's purpose.
 *   - status      (string) Either 'active' or 'inactive'.
 *   - parent_id   (int|null) ID of the parent forum/category, or null for
 *                            top-level categories.
 *   - children    (array)  Nested sub-forums (populated by build_tree()).
 *
 * How the hierarchy works
 * -----------------------
 * Forums are stored as a flat list keyed by their ID.  A forum becomes a
 * sub-forum of another simply by setting its parent_id to the other forum's
 * ID.  The helper build_tree() walks the flat list and returns a nested array
 * so callers can render or traverse the full tree.
 *
 *  Top-level category (parent_id = null)
 *  └─ Sub-forum        (parent_id = <category id>)
 *     └─ Sub-sub-forum  (parent_id = <sub-forum id>)
 */

// ---------------------------------------------------------------------------
// The master forums store (flat list, keyed by forum ID).
// ---------------------------------------------------------------------------
$forums = [];

// ---------------------------------------------------------------------------
// has_ancestor()  –  circular-dependency guard used by add_or_edit_forum().
// ---------------------------------------------------------------------------

/**
 * Check whether $ancestor_id is an ancestor of $forum_id in the tree.
 *
 * Walks upward from $forum_id through parent_id links.  Returns true if
 * $ancestor_id is encountered before reaching the root, which would indicate
 * a circular reference.
 *
 * @param  int   $forum_id    The forum whose ancestry is being checked.
 * @param  int   $ancestor_id The ID to search for among the ancestors.
 * @param  array $forums      The flat forums store.
 * @return bool
 */
function has_ancestor(int $forum_id, int $ancestor_id, array $forums): bool
{
    $visited = [];
    $current = $forum_id;

    while ($current !== null) {
        if (!array_key_exists($current, $forums)) {
            break;
        }
        if (isset($visited[$current])) {
            // Broken chain – stop to avoid an infinite loop.
            break;
        }
        $visited[$current] = true;

        $parent = $forums[$current]['parent_id'];
        if ($parent === null) {
            break;
        }
        if ($parent === $ancestor_id) {
            return true;
        }
        $current = $parent;
    }

    return false;
}

// ---------------------------------------------------------------------------
// add_or_edit_forum()
// ---------------------------------------------------------------------------

/**
 * Add a new forum or edit an existing one in the $forums list.
 *
 * @param array  $data   Associative array describing the forum.
 *                       Required keys when adding:
 *                         - name        (string)
 *                       Optional / defaulted keys:
 *                         - id          (int)      Omit (or set to null) to
 *                                                   create a new forum; supply
 *                                                   an existing ID to edit it.
 *                         - description (string)   Defaults to ''.
 *                         - status      (string)   'active' or 'inactive'.
 *                                                   Defaults to 'active'.
 *                         - parent_id   (int|null) Parent forum ID for nesting.
 *                                                   Defaults to null (top level).
 * @param array &$forums Reference to the flat forums store.
 *
 * @return int The ID of the forum that was added or edited.
 *
 * @throws InvalidArgumentException If required fields are missing or invalid.
 */
function add_or_edit_forum(array $data, array &$forums): int
{
    // -----------------------------------------------------------------------
    // Validate required fields for a new entry.
    // -----------------------------------------------------------------------
    $id = isset($data['id']) ? (int) $data['id'] : null;

    if ($id === null) {
        // Adding a new forum – 'name' is required.
        if (empty($data['name']) || !is_string($data['name'])) {
            throw new InvalidArgumentException("'name' is required and must be a non-empty string when adding a forum.");
        }
    }

    // -----------------------------------------------------------------------
    // Validate status when provided.
    // -----------------------------------------------------------------------
    $allowed_statuses = ['active', 'inactive'];
    if (isset($data['status']) && !in_array($data['status'], $allowed_statuses, true)) {
        throw new InvalidArgumentException(
            "Invalid status '{$data['status']}'. Allowed values: " . implode(', ', $allowed_statuses)
        );
    }

    // -----------------------------------------------------------------------
    // Validate parent_id when provided.
    // -----------------------------------------------------------------------
    if (isset($data['parent_id']) && $data['parent_id'] !== null) {
        $parent_id = (int) $data['parent_id'];
        if (!array_key_exists($parent_id, $forums)) {
            throw new InvalidArgumentException("parent_id {$parent_id} does not exist in the forums list.");
        }
        if ($id !== null) {
            // Prevent direct self-parenting.
            if ($parent_id === $id) {
                throw new InvalidArgumentException("A forum cannot be its own parent.");
            }
            // Prevent circular ancestry (e.g. A → B → A).
            if (has_ancestor($parent_id, $id, $forums)) {
                throw new InvalidArgumentException(
                    "Setting parent_id to {$parent_id} would create a circular reference."
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Edit an existing forum.
    // -----------------------------------------------------------------------
    if ($id !== null && array_key_exists($id, $forums)) {
        $forum = &$forums[$id];

        if (isset($data['name'])) {
            $forum['name'] = (string) $data['name'];
        }
        if (isset($data['description'])) {
            $forum['description'] = (string) $data['description'];
        }
        if (isset($data['status'])) {
            $forum['status'] = $data['status'];
        }
        if (array_key_exists('parent_id', $data)) {
            $forum['parent_id'] = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        }

        return $id;
    }

    // -----------------------------------------------------------------------
    // Add a new forum.
    // -----------------------------------------------------------------------

    // Use a static counter so ID generation is O(1) regardless of list size.
    static $next_id = 1;

    if ($id === null) {
        // Ensure we never collide with a manually supplied ID that was already
        // inserted into $forums by a previous call that bypassed the counter.
        while (array_key_exists($next_id, $forums)) {
            $next_id++;
        }
        $id = $next_id++;
    } else {
        // A specific new ID was provided; advance the counter past it.
        if ($id >= $next_id) {
            $next_id = $id + 1;
        }
    }

    $forums[$id] = [
        'id'          => $id,
        'name'        => (string) $data['name'],
        'description' => isset($data['description']) ? (string) $data['description'] : '',
        'status'      => isset($data['status'])      ? $data['status']               : 'active',
        'parent_id'   => (isset($data['parent_id']) && $data['parent_id'] !== null)
                            ? (int) $data['parent_id']
                            : null,
        'children'    => [],
    ];

    return $id;
}

// ---------------------------------------------------------------------------
// build_tree()  –  helper to convert the flat list into a nested tree.
// ---------------------------------------------------------------------------

/**
 * Convert the flat $forums list into a nested tree structure.
 *
 * Each forum entry will have its 'children' key populated with its direct
 * child forums (recursively).
 *
 * @param  array    $forums    The flat forums store.
 * @param  int|null $parent_id The parent ID to start from (null = top level).
 * @return array    Nested array of forums.
 */
function build_tree(array $forums, ?int $parent_id = null): array
{
    $tree = [];

    foreach ($forums as $forum) {
        if ($forum['parent_id'] === $parent_id) {
            $forum['children'] = build_tree($forums, $forum['id']);
            $tree[]            = $forum;
        }
    }

    return $tree;
}

// ---------------------------------------------------------------------------
// Demo  –  shows how the system works end-to-end.
// ---------------------------------------------------------------------------

// 1. Add top-level categories.
$cat_general  = add_or_edit_forum(['name' => 'General',     'description' => 'General discussion topics.'], $forums);
$cat_tech     = add_or_edit_forum(['name' => 'Technology',  'description' => 'Tech talk.'],                $forums);

// 2. Add sub-forums beneath 'Technology'.
$forum_php    = add_or_edit_forum(['name' => 'PHP',         'description' => 'PHP programming.',  'parent_id' => $cat_tech], $forums);
$forum_js     = add_or_edit_forum(['name' => 'JavaScript',  'description' => 'JS & friends.',     'parent_id' => $cat_tech], $forums);

// 3. Add a sub-sub-forum beneath 'PHP'.
$forum_laravel = add_or_edit_forum(['name' => 'Laravel',    'description' => 'Laravel framework.', 'parent_id' => $forum_php], $forums);

// 4. Edit an existing forum (deactivate JavaScript).
add_or_edit_forum(['id' => $forum_js, 'status' => 'inactive'], $forums);

// 5. Build the nested tree and display it.
$tree = build_tree($forums);

echo "=== Hierarchical Forum Structure ===\n\n";

/**
 * Recursively print the forum tree.
 *
 * @param array $nodes  Tree nodes to print.
 * @param int   $depth  Current depth (for indentation).
 */
function print_tree(array $nodes, int $depth = 0): void
{
    foreach ($nodes as $node) {
        $indent = str_repeat('  ', $depth);
        $status = $node['status'] === 'inactive' ? ' [inactive]' : '';
        echo "{$indent}[{$node['id']}] {$node['name']}{$status}\n";
        if (!empty($node['description'])) {
            echo "{$indent}    {$node['description']}\n";
        }
        if (!empty($node['children'])) {
            print_tree($node['children'], $depth + 1);
        }
    }
}

print_tree($tree);
