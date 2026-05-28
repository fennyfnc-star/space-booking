<?php declare(strict_types=1);

namespace SpaceBooking\Services\Traits;

/**
 * Trait for managing bidirectional conflict group relationships between spaces.
 * Handles dependency tracking for space conflict detection.
 */
trait HasConflictGroups
{
    /**
     * Get all space IDs in the bidirectional conflict group for a space (downstream deps + upstream parents + recursion)
     * @return array<int> Unique space IDs
     */
    public function get_conflict_group_ids(int $space_id): array
    {
        $group = [$space_id];
        $visited = [$space_id => true];

        // Bidirectional DFS
        $this->collect_conflicts($space_id, $group, $visited);

        return array_values($group);
    }

    private function collect_conflicts(int $id, array &$group, array &$visited): void
    {
        global $wpdb;

        // Downstream: my dependencies
        $my_deps = get_post_meta($id, '_sb_resource_dependencies', true) ?: [];
        foreach ((array) $my_deps as $child_id) {
            $child_id = (int) $child_id;
            if ($child_id && !isset($visited[$child_id])) {
                $visited[$child_id] = true;
                $group[] = $child_id;
                $this->collect_conflicts($child_id, $group, $visited);
            }
        }

        // Upstream: spaces that depend on me (reverse edges)
        $parents = $wpdb->get_col($wpdb->prepare("
            SELECT pm.post_id 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sb_resource_dependencies' 
        \t  AND pm.meta_value LIKE %s
        \t  AND p.post_type = 'sb_space'
        \t  AND pm.post_id != %d
        ", '%i:' . $id . ';%', $id));
        foreach ($parents as $parent_id) {
            if (!isset($visited[$parent_id])) {
                $visited[$parent_id] = true;
                $group[] = $parent_id;
                $this->collect_conflicts($parent_id, $group, $visited);
            }
        }
    }

    /**
     * Get unioned conflict groups for multiple space IDs
     */
    public function get_conflict_groups(array $space_ids): array
    {
        $all_conflicts = [];
        $master_visited = [];

        foreach ($space_ids as $id) {
            if (!isset($master_visited[$id])) {
                $this->collect_conflicts($id, $all_conflicts, $master_visited);
            }
        }

        return array_unique($all_conflicts);
    }
}
