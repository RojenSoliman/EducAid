# Fix Offline Mode CSS Issue

## Problem
Bootstrap Icons are loaded from CDN (`https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css`) which causes CSS to "collapse" when internet is unavailable in offline mode.

## Solution
Replace all CDN references with local Bootstrap Icons CSS file that already exists at:
`../../assets/css/bootstrap-icons.css`

## Files to Fix

### Student Module Files (13 files):
1. ✅ `modules/student/student_notifications.php` - Line 74
2. ✅ `modules/student/ignore_upload_document.php` - Line 1722
3. ✅ `modules/student/qr_code.php` - Line 51
4. ✅ `modules/student/upload_document.php` - Line 165
5. ✅ `modules/student/student_settings.php` - Line 294
6. ✅ `modules/student/student_profile.php` - Line 378
7. ✅ `modules/student/student_register.php` - Line 615
8. ✅ `modules/student/register_test.php` - Line 613
9. ✅ `modules/student/student_homepage.php` - Line 118
10. ✅ `modules/student/ignore_student_register_experimental.php` - Line 613
11. ✅ `modules/student/ignore_student_register2.php` - Line 114
12. ✅ `modules/student/index.php` - Line 9

### Change Pattern:
**FROM:**
```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
```

**TO:**
```html
<link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
```

## Verification Steps

1. **Check Local File Exists:**
   ```bash
   dir c:\xampp\htdocs\EducAid\assets\css\bootstrap-icons.css
   ```

2. **Test Offline Mode:**
   - Disconnect from internet
   - Clear browser cache
   - Navigate to student pages
   - Verify icons display correctly

3. **Test Icons:**
   - Bell icon (bi-bell)
   - Person icon (bi-person-circle)
   - List icon (bi-list)
   - All notification icons

## Benefits of Local Bootstrap Icons

✅ **Works Offline** - No internet required  
✅ **Faster Loading** - No external network calls  
✅ **Version Control** - Consistent icon set across all environments  
✅ **Privacy** - No external tracking from CDN  
✅ **Reliability** - Not dependent on external CDN uptime  

## Long-term Maintenance

### Best Practice:
Always use local assets for core dependencies:
- Bootstrap CSS: `../../assets/css/bootstrap.min.css` ✅
- Bootstrap JS: `../../assets/js/bootstrap.bundle.min.js` ✅
- Bootstrap Icons: `../../assets/css/bootstrap-icons.css` ✅

### When to Use CDN:
Only for optional third-party libraries:
- Analytics scripts
- Optional widgets
- Non-critical enhancements

## Rollback Plan
If issues occur, revert to CDN temporarily:
```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
```

## Testing Checklist

- [ ] Student Notifications page displays correctly
- [ ] Bell icon shows in header
- [ ] Notification icons display in dropdown
- [ ] Profile icon displays in header
- [ ] Sidebar icons display correctly
- [ ] All pages work without internet connection
- [ ] No console errors about failed icon loads
- [ ] Icons render at correct size/color

---

**Status:** Ready to implement  
**Priority:** High (affects offline usability)  
**Impact:** Low risk (only changing CSS source, same icon set)
