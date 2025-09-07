-- Fix Car History Triggers - Remove Username Column References
-- Issue: Unknown column 'username' in 'OLD' error during car updates
-- 
-- The username column was removed from the cars table but triggers still reference it
-- This causes database errors when updating cars: "ERROR #42S22: Unknown column 'username' in 'OLD'"
--
-- This script removes the username column references from all three car history triggers
-- Generated: 2025-09-07

-- Drop existing triggers
DROP TRIGGER IF EXISTS cars_insert;
DROP TRIGGER IF EXISTS cars_update;  
DROP TRIGGER IF EXISTS cars_delete;

-- Recreate cars_insert trigger without username references
DELIMITER //
CREATE TRIGGER cars_insert
AFTER INSERT ON cars
FOR EACH ROW
BEGIN
    INSERT INTO cars_hist(
        operation,
        car_id,
        ctime,
        mtime,
        ModifiedBy,
        model,
        series,
        variant,
        year,
        type,
        chassis,
        color,
        engine,
        purchasedate,
        solddate,
        comments,
        image,
        user_id,
        email,
        fname,
        lname,
        join_date,
        city,
        state,
        country,
        lat,
        lon,
        website
    )
    VALUES (
        'INSERT',
        NEW.id,
        NEW.ctime,
        NEW.mtime,
        NEW.ModifiedBy,
        NEW.model,
        NEW.series,
        NEW.variant,
        NEW.year,
        NEW.type,
        NEW.chassis,
        NEW.color,
        NEW.engine,
        NEW.purchasedate,
        NEW.solddate,
        NEW.comments,
        NEW.image,
        NEW.user_id,
        NEW.email,
        NEW.fname,
        NEW.lname,
        NEW.join_date,
        NEW.city,
        NEW.state,
        NEW.country,
        NEW.lat,
        NEW.lon,
        NEW.website
    );
END//

-- Recreate cars_update trigger without username references
CREATE TRIGGER cars_update
AFTER UPDATE ON cars
FOR EACH ROW
BEGIN
    IF @disable_triggers IS NULL THEN 
        INSERT INTO cars_hist(
            operation,
            car_id,
            ctime,
            mtime,
            ModifiedBy,
            model,
            series,
            variant,
            year,
            type,
            chassis,
            color,
            engine,
            purchasedate,
            solddate,
            comments,
            image,
            user_id,
            email,
            fname,
            lname,
            join_date,
            city,
            state,
            country,
            lat,
            lon,
            website
        )
        VALUES (
            'UPDATE',
            OLD.id,
            OLD.ctime,
            OLD.mtime,
            OLD.ModifiedBy,
            OLD.model,
            OLD.series,
            OLD.variant,
            OLD.year,
            OLD.type,
            OLD.chassis,
            OLD.color,
            OLD.engine,
            OLD.purchasedate,
            OLD.solddate,
            OLD.comments,
            OLD.image,
            OLD.user_id,
            OLD.email,
            OLD.fname,
            OLD.lname,
            OLD.join_date,
            OLD.city,
            OLD.state,
            OLD.country,
            OLD.lat,
            OLD.lon,
            OLD.website
        );
    END IF;
END//

-- Recreate cars_delete trigger without username references  
CREATE TRIGGER cars_delete
AFTER DELETE ON cars
FOR EACH ROW
BEGIN
    INSERT INTO cars_hist(
        operation,
        car_id,
        ctime,
        mtime,
        ModifiedBy,
        model,
        series,
        variant,
        year,
        type,
        chassis,
        color,
        engine,
        purchasedate,
        solddate,
        comments,
        image,
        user_id,
        email,
        fname,
        lname,
        join_date,
        city,
        state,
        country,
        lat,
        lon,
        website
    )
    VALUES (
        'DELETE',
        OLD.id,
        OLD.ctime,
        OLD.mtime,
        OLD.ModifiedBy,
        OLD.model,
        OLD.series,
        OLD.variant,
        OLD.year,
        OLD.type,
        OLD.chassis,
        OLD.color,
        OLD.engine,
        OLD.purchasedate,
        OLD.solddate,
        OLD.comments,
        OLD.image,
        OLD.user_id,
        OLD.email,
        OLD.fname,
        OLD.lname,
        OLD.join_date,
        OLD.city,
        OLD.state,
        OLD.country,
        OLD.lat,
        OLD.lon,
        OLD.website
    );
END//

DELIMITER ;

-- Verify triggers were created successfully
SELECT 
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE
FROM information_schema.TRIGGERS 
WHERE EVENT_OBJECT_TABLE = 'cars' 
ORDER BY TRIGGER_NAME;