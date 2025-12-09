# Elan Registry v[VERSION] Release Notes
**Release Date:** [DATE]
**Type:** [Patch/Minor/Major] Release - [Brief Description]

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### [Action Category]: [Brief Description]
**⚠️ [Warning/Note about manual steps required]**

1. **[Action 1]** *(via [method/interface]*
   - [Step 1 details]
   - [Step 2 details]
   - [Step 3 details]
   - Confirm successful [outcome]:
     - [Expected result 1]
     - [Expected result 2]

2. **[Action 2]** *(via [method/interface]*
   - [Step 1 details]
   - [Step 2 details]

3. **[Action 3]**
   - [Step 1 details]
   - [Step 2 details]

**🎯 Success Criteria:**
- ✅ [Completed item] *(COMPLETED)*
- ⏳ [Pending item] *(PENDING - requires [action])*
- ⏳ [Pending item] *(PENDING - requires [action])*

## 👤 User-Facing Changes

[Describe visible changes for end users, or state "No visible changes for end users" if this is an internal release]

### [Feature Category] (if applicable)
- **[Feature 1]**: [Brief description of user benefit]
- **[Feature 2]**: [Brief description of user benefit]

### [Bug Fixes] (if applicable)
- **Fixed [issue]**: [Brief description of what was fixed]
- **Improved [feature]**: [Brief description of improvement]

## 🔧 Admin-Facing Changes

### [Admin Feature Category]
- **[Change 1]**: [Brief description of admin-facing improvement]
- **[Change 2]**: [Brief description of admin-facing improvement]
- **[Change 3]**: [Brief description of admin-facing improvement]

### [Admin Tools/Interface] (if applicable)
- **[Tool/Interface name]**: [Brief description of changes]
- **[Feature]**: [Brief description of enhancement]

## 📋 Issues Resolved in This Release

[#ISSUE_NUMBER](https://github.com/unibrain1/elanregistry/issues/ISSUE_NUMBER) - [Issue Title]

[#ISSUE_NUMBER](https://github.com/unibrain1/elanregistry/issues/ISSUE_NUMBER) - [Issue Title]

[#ISSUE_NUMBER](https://github.com/unibrain1/elanregistry/issues/ISSUE_NUMBER) - [Issue Title]

---

## 📝 Template Usage Notes

### Section Guidelines:
- **Required Actions**: Always include if manual steps are needed post-deployment
- **User-Facing Changes**: Focus on what end users will notice
- **Admin-Facing Changes**: Focus on administrative tools and interfaces
- **Issues Resolved**: Simple list with links to GitHub issues

### Content Guidelines:
- Keep descriptions brief and focused on impact/benefit
- Detailed technical changes belong in the GitHub issues themselves
- Use bullet points for easy scanning
- Include specific action items with clear success criteria
- Remove any sections that don't apply to the release

### Release Requirements:
- **MANDATORY**: Release notes must be created for ALL major (x.0.0) and minor (x.y.0) version releases
- **MANDATORY**: GitHub releases must be created for ALL major and minor versions using `gh release create`
- **Patch releases (x.y.z)**: Release notes and GitHub releases are optional but recommended for significant patches
- **Git tags**: All feature versions (major.minor) must have corresponding git tags

### Formatting Guidelines:
- Use consistent emoji headers for visual organization
- Include links to relevant GitHub issues
- Use checkmarks (✅) for completed items, clocks (⏳) for pending
- Bold important action items and feature names
- Keep lists parallel in structure

### Example Replacements:
- `[VERSION]` → `2.8.7`, `2.9.0`, `3.0.0`
- `[DATE]` → `October 15, 2025`
- `[Patch/Minor/Major]` → Choose based on semantic versioning
- `[Brief Description]` → `Testing Infrastructure`, `User Experience`, etc.
- `[ISSUE_NUMBER]` → Actual GitHub issue number

**Delete this "Template Usage Notes" section when creating actual release notes.**