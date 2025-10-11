# Quick Test Guide - Experimental Login Page

## 🚀 Test URL
```
http://localhost/EducAid/unified_login_experiment.php
```

## 🔍 What to Look For

### ✅ GOOD (Fixed):
- Navbar has visible white space on left/right sides (on wide screens)
- Navbar content (logo, buttons) stays within centered 1320px area
- Purple experimental banner at top
- Login form works normally

### ❌ BAD (Still Broken):
- Navbar stretches across entire screen width
- No gaps on sides of navbar
- Navbar buttons touch screen edges
- Page looks cramped or weird

## 🛠️ Quick DevTools Check

1. Press `F12` to open DevTools
2. Click "Elements" tab
3. Find `<nav class="navbar fixed-header">`
4. Find nested `<div class="container-fluid">`
5. Look at "Styles" panel on right
6. Verify you see:
   ```css
   max-width: 1320px !important;  /* Should NOT be crossed out */
   width: 100%;
   ```

## 🔄 If Still Broken

1. **Hard Refresh:** `Ctrl + F5`
2. **Clear Cache:** `Ctrl + Shift + Delete` → Clear "Cached images and files"
3. **Try Incognito:** `Ctrl + Shift + N` → Open experiment URL
4. **Check Console:** F12 → Console tab → Look for errors

## 📊 Compare Pages

| Feature | Original | Experimental |
|---------|----------|--------------|
| URL | `unified_login.php` | `unified_login_experiment.php` |
| Navbar | Hidden (removed) | Visible (with fixes) |
| Banner | None | Purple "EXPERIMENTAL" banner |

## ✉️ What to Report

If navbar is **STILL ENLARGED**, provide:
1. Screenshot of full page
2. Screenshot of DevTools showing `.container-fluid` styles
3. Browser name and version
4. Screen resolution

If navbar is **FIXED**, confirm:
- ✅ Navbar width is constrained
- ✅ Login form still works
- ✅ No layout issues

---

**Quick Answer:**
- **Works?** → We can apply fixes to main login page
- **Broken?** → Need to investigate deeper (might be browser-specific)
