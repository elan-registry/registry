# 🚀 **Database Column Standardization - Maintenance Window Checklist**

**Date:** ________________  **Time:** ________________  **Duration:** 50 minutes

## **Pre-Migration Checklist** ✅ (All Complete)
- [x] Database migration script created (`05-Database-Column-Standardization-carid-to-car_id.php`)
- [x] All PHP files updated to use `car_id` instead of `carid`
- [x] Post-migration validation test created
- [x] FIX script system modernized and documented
- [x] Access permissions configured properly

---

## **Maintenance Window Execution**

### **1. Pre-Window Preparation** (15 minutes before)
**Start Time:** ____________

- [ ] **Navigate to application directory**
  ```bash
  cd /Users/jimboone/Documents/Developer/Web/elan_registry
  ```

- [ ] **Verify current git status**
  ```bash
  git status
  git log --oneline -5
  ```

- [ ] **Test site accessibility**
  ```bash
  curl -I https://elanregistry.org
  ```

**Completion Time:** ____________

---

### **2. Maintenance Mode** (Start of window)
**Start Time:** ____________

- [ ] **Enable maintenance mode**
  - [ ] Navigate to Dashboard -> General Settings
  - [ ] Select "Maintenance Mode" option
  - [ ] Enable maintenance mode
  - [ ] Verify maintenance page displays to users (test in incognito/different browser)

**Completion Time:** ____________

---

### **3. Final Database Backup** (5 minutes)
**Start Time:** ____________

- [ ] **Create timestamped full backup**
  ```bash
  /Applications/MAMP/Library/bin/mysqldump -h localhost -P 8889 -u claude -p"claude" \
    elanregi_spice > maintenance_backup_$(date +%Y%m%d_%H%M%S).sql
  ```

- [ ] **Verify backup file**
  ```bash
  ls -lh maintenance_backup_*.sql
  head -50 maintenance_backup_*.sql
  ```
  - [ ] File size reasonable: _______ MB
  - [ ] Contains expected table structures

**Backup File:** `maintenance_backup___________________.sql`  
**Completion Time:** ____________

---

### **4. Execute Migration** (10-15 minutes)
**Start Time:** ____________

- [ ] **Navigate to FIX directory**
  - [ ] Open: `https://elanregistry.org/FIX/index.php`
  - [ ] Verify all scripts visible and accessible

- [ ] **Run migration script**
  - [ ] Click "05-Database-Column-Standardization-carid-to-car_id.php"
  - [ ] Monitor real-time progress
  - [ ] Wait for "✅ SUCCESS" completion message

- [ ] **Migration automatically completed:**
  - [ ] Pre-migration backup created
  - [ ] Current state validated
  - [ ] Column renames executed atomically
  - [ ] All changes verified
  - [ ] Rollback script generated (if needed)
  - [ ] Completion recorded

**Migration Results:**
- **Records Processed:** ____________
- **Success Rate:** ____________%
- **Errors:** ____________

**Completion Time:** ____________

---

### **5. Post-Migration Verification** (10 minutes)
**Start Time:** ____________

- [ ] **Run validation test**
  ```bash
  vendor/bin/phpunit tests/DatabaseMigrationValidationTest.php
  ```
  - [ ] All tests passed: ____________

- [ ] **Test critical functionality via browser:**
  - [ ] User login/logout works
  - [ ] Car search returns results
  - [ ] Car profile pages load correctly
  - [ ] Admin car management accessible
  - [ ] Car history displays properly

- [ ] **Check application logs**
  ```bash
  tail -f /Applications/MAMP/logs/php_error.log
  ```
  - [ ] No critical errors found

**Issues Found:** ____________________________________________

**Completion Time:** ____________

---

### **6. Smoke Tests** (5 minutes)
**Start Time:** ____________

- [ ] **Homepage** loads correctly
- [ ] **User authentication** works
- [ ] **Car search** returns results
- [ ] **Individual car pages** display properly
- [ ] **Car history** shows correctly
- [ ] **Admin functions** accessible
- [ ] **No PHP fatal errors** in logs

**Test Results:** ____________________________________________

**Completion Time:** ____________

---

### **7. Disable Maintenance Mode**
**Start Time:** ____________

- [ ] **Disable maintenance mode**
  - [ ] Navigate to Dashboard -> General Settings
  - [ ] Locate "Maintenance Mode" option
  - [ ] Disable maintenance mode
  - [ ] Clear any caches if applicable

- [ ] **Verify public site accessibility**
  ```bash
  curl -I https://elanregistry.org
  ```
  - [ ] Site responds normally (test in incognito/different browser)

**Completion Time:** ____________

---

### **8. Post-Window Monitoring** (First 2 hours)
**Start Time:** ____________

- [ ] **Monitor error logs continuously**
- [ ] **Check user reports/feedback**
- [ ] **Verify all database queries working**
- [ ] **Confirm search functionality**
- [ ] **Test user registration/car submissions**

**Monitoring Notes:** ____________________________________________

---

## **Emergency Rollback Plan** (If Issues Occur)

### **Rollback Steps:**
- [ ] **Restore from pre-migration backup**
  ```bash
  /Applications/MAMP/Library/bin/mysql -h localhost -P 8889 -u claude -p"claude" \
    elanregi_spice < maintenance_backup_[timestamp].sql
  ```

- [ ] **Revert code changes (if needed)**
  ```bash
  git stash  # Save any manual fixes
  git reset --hard [commit_before_migration]
  ```

- [ ] **Test functionality** - Run smoke tests again

- [ ] **Disable maintenance mode**

**Rollback Executed:** [ ] Yes [ ] No  
**Rollback Time:** ____________

---

## **Success Criteria Verification**
- [ ] All database columns successfully renamed
- [ ] No PHP errors or warnings
- [ ] All core functionality working
- [ ] Search and display features operational
- [ ] User authentication functioning
- [ ] Car history displaying correctly

---

## **Final Sign-off**

**Migration Status:** [ ] Success [ ] Partial Success [ ] Failed [ ] Rolled Back

**Total Maintenance Window Duration:** ____________

**Issues Encountered:**
____________________________________________
____________________________________________
____________________________________________

**Next Steps:**
____________________________________________
____________________________________________
____________________________________________

**Completed By:** ________________  **Date/Time:** ________________

---

## **Contact Information**
- **Database Issues:** Check `/Applications/MAMP/logs/mysql_error.log`
- **PHP Errors:** Check `/Applications/MAMP/logs/php_error.log`
- **Migration Log:** Available in browser via FIX script interface

---

**Estimated Timeline:**
- **Preparation:** 15 minutes
- **Backup & Migration:** 20 minutes  
- **Verification & Testing:** 15 minutes
- **Total Window:** 50 minutes