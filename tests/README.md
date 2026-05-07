# Space Booking Test Suite

This directory contains test files for the Space Booking plugin. Run tests via browser by navigating to each test file URL.

## Test Files

### 1. verify-refactor.php

**Purpose:** Unified Resource-Based Booking Architecture verification
**Tests:**

- Test A: Multi-Space Booking Creation
- Test B: Single-Space Detection
- Test C: Common Slot Intersection
- Test D: Conflict Messaging
  **URL:** `/wp-content/plugins/space-booking/tests/verify-refactor.php`

### 2. TestPendingBlocking.php

**Purpose:** Pending slot blocking functionality
**Tests:**

- Test 1: Pending non-expired blocks slot
- Test 2: Expired pending does NOT block
- Test 3: Available slots reflect pending blocking
- Test 4: Cleanup removes expired
- Test 5: Get pending intervals method
  **URL:** `/wp-content/plugins/space-booking/tests/TestPendingBlocking.php`

### 3. TestExtrasValidation.php

**Purpose:** Extras validation and handling
**Tests:**

- Test 1: No extras returns empty
- Test 2: With extras returns correct data
- Test 3: Invalid extra_id excluded
- Test 4: Title included in get_extras
  **URL:** `/wp-content/plugins/space-booking/tests/TestExtrasValidation.php`

### 4. TestMultiSpaceBooking.php

**Purpose:** Multi-space booking functionality
**Tests:**

- Test 1: Create lead booking
- Test 2: Create secondary row
- Test 3: Query by order_id
- Test 4: Single space blocks
- Test 5: Intersection shows blockers
- Test 6: All spaces blocked
- Test 7: Status update
  **URL:** `/wp-content/plugins/space-booking/tests/TestMultiSpaceBooking.php`

### 5. check-schema.php

**Purpose:** Database schema verification
**URL:** `/wp-content/plugins/space-booking/tests/check-schema.php`

### 6. test-cpts-complete.php

**Purpose:** CPT registration verification
**URL:** `/wp-content/plugins/space-booking/tests/test-cpts-complete.php`

## Running Tests

### Via Browser

Navigate to any test file URL in your browser:

```
http://your-site.com/wp-content/plugins/space-booking/tests/verify-refactor.php
```

### Via Command Line (optional)

```bash
cd /path/to/wordpress
php wp-content/plugins/space-booking/tests/verify-refactor.php
```

## Test Data

Tests use the following space IDs for testing:

- `101` - Primary test space
- `102` - Secondary test space
- `103` - Third test space

Test dates are set to future dates (2026+) to avoid conflicts with real bookings.

## Cleanup

All test files include automatic cleanup to remove test data after running. However, if tests are interrupted, you can manually clean up:

```sql
DELETE FROM wp_sb_bookings WHERE booking_date LIKE '2026-%' AND space_id IN (101, 102, 103);
```

## Pest Tests (Advanced)

The project also includes Pest tests in `VerifyRefactorTest.php`. These require:

- Composer dependencies installed: `composer install`
- WordPress test environment setup

```bash
vendor/bin/pest tests/VerifyRefactorTest.php
```

## Adding New Tests

When adding new tests:

1. Create a new PHP file in this directory
2. Include WordPress bootstrap
3. Use future dates (2026+) for test data
4. Include cleanup function
5. Return clear PASS/FAIL results
6. Update this README
