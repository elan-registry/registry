-- ==================================================================
-- ELAN REGISTRY - SAMPLE USER CONFIGURATION (Section 5.4)
-- ==================================================================
-- This script adds a sample user based on the development admin user
-- Run AFTER 5.1-schema.sql, 5.2-import_reference_data.sql, and 5.3-essential_config.sql
-- ==================================================================

-- ==================================================================
-- 1. CREATE SAMPLE USER ACCOUNT
-- ==================================================================

-- Add sample user account (ID 2, since admin gets ID 1 in fresh UserSpice)
-- Based on development admin user but with generic credentials
INSERT INTO `users` (
    `id`, 
    `email`, 
    `username`, 
    `password`, 
    `pin`, 
    `fname`, 
    `lname`, 
    `permissions`, 
    `logins`, 
    `account_owner`, 
    `account_id`, 
    `join_date`, 
    `last_login`, 
    `email_verified`, 
    `vericode`, 
    `active`, 
    `oauth_provider`, 
    `oauth_uid`, 
    `gender`, 
    `locale`, 
    `gpluslink`, 
    `picture`, 
    `created`, 
    `modified`, 
    `fb_uid`, 
    `un_changed`, 
    `msg_exempt`, 
    `protected`, 
    `dev_user`, 
    `msg_notification`, 
    `force_pr`, 
    `cloak_allowed`, 
    `vericode_expiry`, 
    `oauth_tos_accepted`, 
    `language`, 
    `account_mgr`
) VALUES (
    2,  -- Next available user ID
    'sample@elanregistry.org',  -- Generic email
    'sample_user',  -- Generic username
    '$2y$13$PAjtKFE6ctr3hOgzIuhITeNHC8XtjcLMSRE/zhj5u.uMcs7PMgCQy',  -- Password: 'password123'
    '$2y$12$/xAft.7hBI7WCEnDyKaQquxq2QAuNfbpt7h7MEb0P7Vsv6UMLs0.m',  -- PIN hash
    'Sample',  -- First name
    'User',  -- Last name
    1,  -- Standard user permissions
    0,  -- Login count (starts at 0)
    1,  -- Account owner
    0,  -- Account ID
    NOW(),  -- Join date
    NULL,  -- Last login (NULL for new user)
    1,  -- Email verified
    'SampleUserVeriCode123',  -- Verification code
    1,  -- Active account
    NULL,  -- OAuth provider
    NULL,  -- OAuth UID
    NULL,  -- Gender
    NULL,  -- Locale
    NULL,  -- Google+ link
    NULL,  -- Picture
    NOW(),  -- Created timestamp
    NOW(),  -- Modified timestamp
    NULL,  -- Facebook UID
    0,  -- Username unchanged
    0,  -- Message exempt (regular user)
    0,  -- Not protected (not admin)
    1,  -- Development user flag
    1,  -- Message notifications enabled
    0,  -- Force password reset
    1,  -- Cloak allowed
    DATE_ADD(NOW(), INTERVAL 24 HOUR),  -- Verification code expires in 24 hours
    1,  -- OAuth terms accepted
    'en-US',  -- Language
    0  -- Account manager
) ON DUPLICATE KEY UPDATE 
    email = VALUES(email),
    username = VALUES(username),
    fname = VALUES(fname),
    lname = VALUES(lname);

-- ==================================================================
-- 2. CREATE SAMPLE USER PROFILE
-- ==================================================================

-- Add enhanced profile with geographic location data
INSERT INTO `profiles` (
    `id`,
    `user_id`, 
    `bio`, 
    `city`, 
    `state`, 
    `country`, 
    `lat`, 
    `lon`, 
    `website`
) VALUES (
    2,  -- Profile ID matches user ID
    2,  -- User ID reference
    'Sample user for testing and demonstration purposes. This user represents a typical Lotus Elan Registry member.',  -- Generic bio
    'Portland',  -- City
    'Oregon',  -- State  
    'United States',  -- Country
    45.51,  -- Latitude
    -122.68,  -- Longitude
    'https://www.elanregistry.org/'  -- Generic website
) ON DUPLICATE KEY UPDATE 
    bio = VALUES(bio),
    city = VALUES(city),
    state = VALUES(state),
    country = VALUES(country),
    lat = VALUES(lat),
    lon = VALUES(lon),
    website = VALUES(website);

-- ==================================================================
-- 3. SAMPLE USER PERMISSIONS
-- ==================================================================

-- Add user to standard User permission (permission_id = 1)
-- This is typically handled automatically by UserSpice, but we'll ensure it's set
INSERT INTO `user_permission_matches` (`user_id`, `permission_id`) VALUES 
(2, 1)  -- Standard User permission
ON DUPLICATE KEY UPDATE 
    user_id = VALUES(user_id),
    permission_id = VALUES(permission_id);

-- ==================================================================
-- 4. SAMPLE CAR RECORD
-- ==================================================================

-- Add sample car (Car ID 1) owned by sample user
INSERT INTO `cars` (
    `id`,
    `user_id`, 
    `model`, 
    `series`, 
    `variant`, 
    `year`, 
    `type`, 
    `chassis`, 
    `color`, 
    `engine`, 
    `purchasedate`, 
    `comments`, 
    `image`,
    `fname`, 
    `lname`, 
    `email`, 
    `city`, 
    `state`, 
    `country`, 
    `lat`, 
    `lon`, 
    `website`, 
    `ctime`, 
    `mtime`,
    `vericode`,
    `last_verified`,
    `ModifiedBy`
) VALUES (
    1,  -- Car ID 1
    2,  -- Owned by sample_user (ID 2)
    'Lotus Elan',
    'S4',
    'SE',
    '1973',
    'FHC',
    '45/0123A',  -- Type 45 chassis with suffix A
    'Signal Red',
    'ABC123',
    '2020-01-15',
    'Beautiful restored example with matching numbers. Recent full restoration completed in 2019. Engine rebuilt with unleaded head conversion. Transmission rebuilt. All suspension bushings replaced. New interior and soft top. Car shows excellent and drives beautifully.',
    '["img_5ff391578d9be6.04210270.jpg","img_60184d777af4d7.90737857.jpg","img_601c1c88b5aa67.07757198.jpg"]',  -- JSON array of actual car images
    'Sample',    -- Duplicated from user profile for performance
    'User',
    'sample@elanregistry.org',
    'Portland',
    'Oregon', 
    'United States',
    45.51,
    -122.68,
    'https://www.elanregistry.org/',
    NOW(),
    NOW(),
    'SampleCarVeriCode123',
    NOW(),
    'sample_user'
) ON DUPLICATE KEY UPDATE 
    model = VALUES(model),
    series = VALUES(series),
    variant = VALUES(variant),
    year = VALUES(year),
    type = VALUES(type),
    chassis = VALUES(chassis),
    color = VALUES(color),
    engine = VALUES(engine);

-- ==================================================================
-- 5. SAMPLE CAR OWNERSHIP RELATIONSHIP
-- ==================================================================

-- Add car ownership record (sample_user owns car ID 1)
INSERT INTO `car_user` (
    `car_id`, 
    `user_id`, 
    `role`, 
    `created_by`, 
    `active`,
    `created_date`
) VALUES (
    1,  -- Car ID 1
    2,  -- User ID 2 (sample_user)
    'owner',  -- Owner role
    2,  -- Created by sample_user
    1,  -- Active relationship
    NOW()
) ON DUPLICATE KEY UPDATE 
    role = VALUES(role),
    active = VALUES(active);

-- ==================================================================
-- 6. SAMPLE CAR HISTORY RECORD
-- ==================================================================

-- Add initial history record for the car (simulating the creation audit trail)
INSERT INTO `cars_hist` (
    `car_id`,
    `operation`,
    `old_values`,
    `new_values`,
    `changed_by`,
    `change_date`,
    `reason`
) VALUES (
    1,  -- Car ID 1
    'INSERT',  -- Operation type
    '{}',  -- No old values for INSERT
    '{"model":"Lotus Elan","series":"S4","variant":"SE","year":"1973","type":"FHC","chassis":"45/0123A","color":"Signal Red","engine":"ABC123","purchasedate":"2020-01-15","comments":"Beautiful restored example with matching numbers. Recent full restoration completed in 2019."}',  -- New values as JSON
    2,  -- Changed by sample_user (ID 2)
    NOW(),  -- Changed at current time
    'Initial car registration by sample user'
) ON DUPLICATE KEY UPDATE 
    operation = VALUES(operation),
    new_values = VALUES(new_values),
    reason = VALUES(reason);

-- ==================================================================
-- 7. RECORD SUCCESSFUL COMPLETION
-- ==================================================================

-- Record this sample user and car creation
INSERT INTO `fix_script_runs` (`script_name`, `status`, `notes`) 
VALUES ('database/5.4-sample_user.sql', 'completed', 'Sample user created: sample_user (ID 2) with profile, permissions, and sample car (ID 1)')
ON DUPLICATE KEY UPDATE 
    run_date = CURRENT_TIMESTAMP,
    status = 'completed',
    notes = 'Sample user updated';

-- ==================================================================
-- 8. VERIFICATION QUERIES
-- ==================================================================

-- Display sample user information
SELECT 'Sample user created:' as status;
SELECT 
    id,
    email,
    username,
    fname,
    lname,
    join_date,
    active,
    email_verified,
    permissions
FROM users WHERE id = 2;

-- Display profile information  
SELECT 'Sample user profile:' as status;
SELECT 
    user_id,
    city,
    state,
    country,
    website,
    CONCAT(ROUND(lat, 2), ', ', ROUND(lon, 2)) as coordinates
FROM profiles WHERE user_id = 2;

-- Display sample car information
SELECT 'Sample car created:' as status;
SELECT 
    id,
    model,
    series,
    variant,
    year,
    type,
    chassis,
    color,
    engine,
    purchasedate,
    SUBSTRING(comments, 1, 50) as comments_preview,
    image
FROM cars WHERE id = 1;

-- Display car ownership
SELECT 'Sample car ownership:' as status;
SELECT 
    cu.car_id,
    cu.user_id,
    u.username,
    cu.role,
    cu.active,
    cu.created_date
FROM car_user cu
JOIN users u ON cu.user_id = u.id
WHERE cu.car_id = 1;

-- Display car history
SELECT 'Sample car history:' as status;
SELECT 
    car_id,
    operation,
    changed_by,
    change_date,
    reason
FROM cars_hist WHERE car_id = 1;

-- Display permissions
SELECT 'Sample user permissions:' as status;
SELECT 
    u.username,
    p.name as permission_name,
    p.descrip as permission_description
FROM users u
JOIN user_permission_matches upm ON u.id = upm.user_id
JOIN permissions p ON upm.permission_id = p.id
WHERE u.id = 2;

-- ==================================================================
-- MANUAL CONFIGURATION NOTES:
-- ==================================================================

/*
SAMPLE USER CREDENTIALS:

Username: sample_user
Email: sample@elanregistry.org
Password: password123
PIN: (matches original admin PIN hash)

IMPORTANT NOTES:

1. **Change Default Password**: 
   - The sample user uses a default password 'password123'
   - Change this immediately after first login
   - Consider forcing password reset on first login

2. **Update Email Address**:
   - Replace sample@elanregistry.org with a real email for testing
   - Ensure email domain is properly configured for notifications

3. **Geographic Location**:
   - Sample user is located in Portland, Oregon
   - Coordinates: 45.51, -122.68
   - This provides a reference point for testing location features

4. **Permission Level**:
   - Sample user has standard User permissions (level 1)
   - Can register cars, edit own cars, use contact features
   - Cannot access administrative functions

5. **Development Flag**:
   - User has dev_user = 1 for development identification
   - Set to 0 for production environments if needed

6. **Sample Car Details**:
   - Car ID 1: 1973 Lotus Elan S4 SE FHC
   - Chassis: 45/0123A (realistic Type 45 format)
   - Color: Signal Red, Engine: ABC123
   - Complete restoration story with detailed comments
   - 3 high-quality car images from existing userimages/1/ directory
   - Full history record and ownership tracking

7. **Testing Purposes**:
   - Use for testing car registration and editing workflows
   - Test location-based features and mapping
   - Verify user permission restrictions
   - Test contact and communication features
   - Test car image display and management
   - Test car sharing and ownership features

8. **Security Considerations**:
   - Sample user is created with email_verified = 1 
   - Account is immediately active for testing
   - Two-factor authentication is disabled by default
   - Consider enabling 2FA for production testing

RECOMMENDED TESTING WORKFLOW:
1. Login as sample_user with password 'password123'
2. View and edit the sample car (Car ID 1) 
3. Test car image display and upload functionality
4. Verify location features work correctly  
5. Test contact forms and owner communication
6. Verify permission restrictions (cannot access admin features)
7. Test profile updates and geographic data sync
8. Test car sharing functionality between users
*/