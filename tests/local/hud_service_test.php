<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Unit tests for hud_service.
 *
 * Tests for get_block_instance_id/is_installed/is_outdated always run. Tests for item/
 * inventory operations that need block_playerhud's own tables are skipped when it is not
 * installed — mirrors mod_playerwords\local\hud_service_test, the proven pattern for this
 * soft dependency already in production for this ecosystem.
 *
 * @package    mod_playervideo
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playervideo\local;

/**
 * Tests for hud_service.
 *
 * @covers \mod_playervideo\local\hud_service
 */
final class hud_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context.
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;
        $context = \context_course::instance($course->id);
        return $DB->insert_record('block_instances', (object) [
            'blockname' => 'playerhud',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize(new \stdClass())),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param string $name Item display name.
     * @param int $xp XP awarded per unit granted.
     * @param bool $enabled Whether the item is enabled.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid, string $name = 'Gold Key', int $xp = 0, bool $enabled = true): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid,
            'name' => $name,
            'xp' => $xp,
            'image' => '',
            'description' => '',
            'enabled' => $enabled ? 1 : 0,
            'secret' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    // Tests for get_block_instance_id (no PlayerHUD tables required).

    /**
     * Tests that null is returned when no playerhud block exists in the course.
     *
     * @return void
     */
    public function test_get_block_instance_id_returns_null_when_absent(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->assertNull(hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that the correct block instance ID is returned when one exists.
     *
     * @return void
     */
    public function test_get_block_instance_id_finds_block(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $this->assertSame($biid, hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that a block in a different course is not returned.
     *
     * @return void
     */
    public function test_get_block_instance_id_ignores_other_course(): void {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $this->make_block_instance($coursea);
        $this->assertNull(hud_service::get_block_instance_id($courseb->id));
    }

    /**
     * Tests that is_installed reflects whether the block_playerhud plugin is present on this
     * site, independently of any course having added a block instance.
     *
     * @return void
     */
    public function test_is_installed_matches_class_presence(): void {
        $this->assertSame(class_exists('\block_playerhud\local\external_items'), hud_service::is_installed());
    }

    /**
     * Tests that is_outdated reflects the definition it exists to check: PlayerHUD's
     * always-present class exists but the item API class is_installed() checks does not.
     *
     * @return void
     */
    public function test_is_outdated_matches_definition(): void {
        $expected = class_exists('\block_playerhud\game') && !hud_service::is_installed();
        $this->assertSame($expected, hud_service::is_outdated());
    }

    /**
     * Tests that resolve_block_instance_id returns 0 (not null) for a course with no
     * PlayerHUD block, so callers can pass it straight into external_items:: without a
     * separate null check.
     *
     * @return void
     */
    public function test_resolve_block_instance_id_returns_zero_when_absent(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = (object) ['course' => $course->id];

        $this->assertSame(0, hud_service::resolve_block_instance_id($instance));
    }

    /**
     * Tests that resolve_block_instance_id resolves the real block instance id for the
     * activity's own course when one exists.
     *
     * @return void
     */
    public function test_resolve_block_instance_id_finds_block(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $instance = (object) ['course' => $course->id];

        $this->assertSame($biid, hud_service::resolve_block_instance_id($instance));
    }

    /**
     * Tests that get_available_quantity, get_item_name, consume_items and grant_items all
     * return their documented neutral values instead of fataling when block_playerhud is not
     * installed — the docblock on the class promises graceful degradation.
     *
     * Every dev/CI environment for this plugin may or may not have block_playerhud installed
     * alongside it, so this only actually executes on a site where the block is absent —
     * exactly the scenario the graceful-degradation contract exists for.
     *
     * @return void
     */
    public function test_item_methods_return_neutral_values_when_not_installed(): void {
        if (hud_service::is_installed()) {
            $this->markTestSkipped('block_playerhud is installed; the fallback path is inert.');
        }

        $this->assertSame(0, hud_service::get_available_quantity(1, 1, 1));
        $this->assertSame('', hud_service::get_item_name(1, 1));
        $this->assertTrue(hud_service::consume_items(1, 1, 1, 1));
        // Void return: reaching this line without a fatal is the assertion.
        hud_service::grant_items(1, 1, 1, 1);
    }

    // Tests that require block_playerhud's own tables.

    /**
     * Tests that is_available_for_course is true once a block instance exists.
     *
     * @return void
     */
    public function test_is_available_for_course_true_with_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course);
        $this->assertTrue(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that is_available_for_course is false when the course has no block instance, even
     * though the block plugin itself is installed.
     *
     * @return void
     */
    public function test_is_available_for_course_false_without_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that get_items_for_block returns only enabled items, sorted by name.
     *
     * @return void
     */
    public function test_get_items_for_block_returns_only_enabled_sorted_by_name(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $this->make_item($biid, 'Zinc Key');
        $this->make_item($biid, 'Alpha Key');
        $this->make_item($biid, 'Hidden', 0, false);

        $items = hud_service::get_items_for_block($biid);

        $this->assertCount(2, $items);
        $this->assertSame('Alpha Key', $items[0]->name);
        $this->assertSame('Zinc Key', $items[1]->name);
    }

    /**
     * Tests that get_item_name returns the item's display name.
     *
     * @return void
     */
    public function test_get_item_name(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Key');

        $this->assertSame('Gold Key', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that get_item_name returns an empty string for an item belonging to a different
     * block instance — the cross-course leak this delegation to external_items prevents.
     *
     * @return void
     */
    public function test_get_item_name_empty_for_other_instance_item(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 'Gold Key');

        $this->assertSame('', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that consume_items returns true (waived, not blocked) for an item belonging to a
     * different block instance — a foreign or deleted item can never be restocked, so the cost
     * is dispensed rather than locking the student out forever.
     *
     * @return void
     */
    public function test_consume_items_waived_for_other_instance_item(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid);

        $this->assertTrue(hud_service::consume_items($biid, $user->id, $itemid, 1));
    }

    /**
     * Tests that consume_items returns false for a genuine insufficient balance on a valid
     * item, and true once the same student is granted enough to cover it — round-tripping
     * grant_items and consume_items against the real PlayerHUD inventory tables.
     *
     * @return void
     */
    public function test_consume_items_reflects_the_real_balance(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Key', 10);

        $this->assertFalse(hud_service::consume_items($biid, $user->id, $itemid, 1));

        hud_service::grant_items($biid, $user->id, $itemid, 2);

        $this->assertSame(2, hud_service::get_available_quantity($biid, $user->id, $itemid));
        $this->assertTrue(hud_service::consume_items($biid, $user->id, $itemid, 2));
        $this->assertSame(0, hud_service::get_available_quantity($biid, $user->id, $itemid));
    }

    /**
     * Tests that granting an item belonging to a different block instance is a no-op — the
     * cross-course leak this delegation to external_items prevents.
     *
     * @return void
     */
    public function test_grant_items_other_instance_item_noop(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 'Gold Key');

        hud_service::grant_items($biid, $user->id, $itemid, 1);

        $this->assertSame(0, hud_service::get_available_quantity($biid, $user->id, $itemid));
    }
}
