# Playwright End-to-End Tests

## Overview

This directory contains comprehensive Playwright tests for the Lotus Elan Registry application, specifically designed to validate the major refactoring work completed in the style-refactor branch.

## Test Suites

### 🧭 **navigation.test.js**
Tests file reorganization and backward compatibility:
- ✅ All reorganized pages load correctly
- ✅ 301 redirects work for old URLs
- ✅ Navigation between sections functions
- ✅ Breadcrumb and menu systems work

### 🔒 **security.test.js**
Validates security implementations:
- ✅ CSRF tokens present in all forms
- ✅ Session cookies have secure flags
- ✅ XSS prevention works
- ✅ Input sanitization functions
- ✅ Verification system security

### ⚙️ **functionality.test.js**
Tests core application features:
- ✅ DataTables search and pagination
- ✅ Car edit form workflow
- ✅ Chassis validation system
- ✅ Contact form submission
- ✅ AJAX endpoints respond correctly

### 🎨 **ui-consistency.test.js**
Validates style refactoring results:
- ✅ Consistent card layouts
- ✅ Responsive design works
- ✅ Button styling consistency
- ✅ Form styling uniformity
- ✅ Mobile compatibility

### 🗺️ **maps-charts.test.js**
Tests JavaScript integrations:
- ✅ Google Maps load and display
- ✅ Google Charts render correctly
- ✅ Map markers and interactions
- ✅ Statistics data visualization
- ✅ Mobile responsiveness of charts

## Running Tests

### Prerequisites
Ensure your local development server is running at `http://localhost:9999/elan_registry`

### Run All Tests
```bash
npm test
```

### Run Specific Test Suites
```bash
npm run test:security      # Security-focused tests
npm run test:ui           # UI consistency tests
npm run test:navigation   # Navigation and redirects
npm run test:functionality # Core functionality
npm run test:maps         # Maps and charts
```

### Debug Mode
```bash
npm run test:debug        # Opens browser with debugging tools
npm run test:headed       # Run tests in headed mode (visible browser)
```

### View Test Reports
```bash
npm run test:report       # Opens HTML test report
```

## Test Configuration

### Browsers Tested
- **Chromium** (Desktop Chrome)
- **Firefox** (Desktop Firefox)
- **WebKit** (Desktop Safari)
- **Mobile Chrome** (Pixel 5)
- **Mobile Safari** (iPhone 12)

### Test Environment
- **Base URL**: `http://localhost:9999/elan_registry`
- **Timeout**: 30 seconds per test
- **Retries**: 2 on CI, 0 locally
- **Screenshots**: Captured on failure
- **Videos**: Recorded on failure

## Test Results Integration

These Playwright tests complement our existing PHPUnit security tests:
- **PHPUnit**: 33/33 security tests passing (1,187 assertions)
- **Playwright**: End-to-end browser validation
- **Combined**: Complete testing coverage

## Common Issues & Solutions

### 🚫 **Server Not Running**
```
Error: connect ECONNREFUSED 127.0.0.1:9999
```
**Solution**: Start your local PHP server on port 9999

### 🗺️ **Google Maps API Issues**
Tests may show warnings about missing API keys - this is expected for local development.

### 📱 **Mobile Tests Failing**
Check viewport settings and ensure responsive CSS is working correctly.

### 🔧 **AJAX Endpoint Errors**
Verify that all action files were moved correctly during reorganization.

## Test Coverage

### ✅ **What We Test**
- File reorganization success
- Security implementations (CSRF, XSS, etc.)
- Core functionality (forms, DataTables, etc.)
- UI consistency and responsiveness
- JavaScript integrations (Maps, Charts)
- Cross-browser compatibility

### ❌ **What We Don't Test**
- Database integration (covered by PHPUnit)
- Email functionality (requires mail server)
- User authentication flows (requires test users)
- File upload processing (requires server setup)

## Maintenance

### Adding New Tests
1. Create test file in appropriate category
2. Follow existing naming conventions
3. Use page object model for complex interactions
4. Add test to package.json scripts if needed

### Updating Tests
When adding new features:
1. Update relevant test files
2. Add new test cases for new functionality
3. Update this README if new test categories added

## Integration with CI/CD

These tests are designed to run in CI/CD pipelines:
- Headless execution by default
- Proper error reporting
- Screenshot/video capture on failure
- HTML reports generated

---

## Quick Start

1. **Install Dependencies**
   ```bash
   npm install
   npx playwright install
   ```

2. **Start Local Server**
   ```bash
   # Your PHP development server on port 9999
   ```

3. **Run Tests**
   ```bash
   npm test
   ```

4. **View Results**
   ```bash
   npm run test:report
   ```

---

*These tests validate the comprehensive refactoring work completed in the style-refactor branch, ensuring all functionality works correctly after the major organizational and security improvements.*