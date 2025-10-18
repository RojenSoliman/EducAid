# Admin Validation View - Quick Reference Guide

## Document Validation Checks by Type

This guide shows what validation checks admins will see when clicking "View Validation" button in Manage Applicants.

---

## 📋 Letter to Mayor (4 Checks)

### What Gets Validated:
1. **First Name Match** - Student's first name found in document (≥80% confidence)
2. **Last Name Match** - Student's last name found in document (≥80% confidence)
3. **Barangay Match** - Student's barangay found in document (≥70% confidence)
4. **Office Header Found** - Official mayor's office header found (≥70% confidence)
   - Looks for: "Office of the Mayor", "City Mayor", "Municipal Mayor", "Mayor's Office", "LGU", etc.

### Success Criteria:
- ✅ **PASS:** 3 or more checks pass
- ✅ **PASS:** 2+ checks pass with 75%+ average confidence
- ❌ **FAIL:** Less than 2 checks pass OR low confidence

### Example Display:
```
✅ Check #1: First Name Match (95.5%)
✅ Check #2: Last Name Match (92.3%)
✅ Check #3: Barangay Match (88.0%)
✅ Check #4: Office Header Found (85.0%)

Overall: 4/4 checks passed (90.2% average confidence)
Recommendation: Document validation successful
```

---

## 🏆 Certificate of Indigency (5 Checks)

### What Gets Validated:
1. **Certificate Title Found** - "Certificate of Indigency" or similar title present (≥70% confidence)
   - Looks for: "Certificate of Indigency", "Indigency Certificate", "Katunayan ng Kahirapan", etc.
2. **First Name Match** - Student's first name found in document (≥80% confidence)
3. **Last Name Match** - Student's last name found in document (≥80% confidence)
4. **Barangay Match** - Student's barangay found in document (≥70% confidence)
5. **General Trias Found** - "General Trias" or variations found (≥70% confidence)
   - Looks for: "General Trias", "Gen Trias", "General Trias City", "Municipality of General Trias", etc.

### Success Criteria:
- ✅ **PASS:** 4 or more checks pass
- ✅ **PASS:** 3+ checks pass with 75%+ average confidence
- ❌ **FAIL:** Less than 3 checks pass OR low confidence

### Example Display:
```
✅ Check #1: Certificate Title Found (95.0%)
✅ Check #2: First Name Match (93.5%)
✅ Check #3: Last Name Match (90.8%)
✅ Check #4: Barangay Match (87.5%)
✅ Check #5: General Trias Found (92.0%)

Overall: 5/5 checks passed (91.8% average confidence)
Recommendation: Certificate validation successful
```

---

## 🆔 ID Picture (6 Checks)

### What Gets Validated:
1. **First Name Match** - Student's first name found in ID (≥80% confidence)
2. **Middle Name Match** - Student's middle name found in ID (≥70% confidence)
   - Auto-pass if student has no middle name
3. **Last Name Match** - Student's last name found in ID (≥80% confidence)
4. **Year Level Match** - Student's year level found in ID
   - Looks for: "1st Year", "Freshman", "2nd Year", "Sophomore", "3rd Year", "Junior", "4th Year", "Senior", etc.
5. **University Match** - Student's university name found in ID (≥60% word match)
6. **Document Keywords Found** - ID-related keywords present (≥2 keywords)
   - Looks for: "Student", "ID", "Identification", "University", "College", "School", "Name", "Number", "Valid", "Card", "Holder", "Expires"

### Success Criteria:
- ✅ **PASS:** 4 or more checks pass
- ✅ **PASS:** 3+ checks pass with 80%+ average confidence
- ❌ **FAIL:** Less than 3 checks pass OR low confidence

### Example Display:
```
✅ Check #1: First Name Match (96.0%)
✅ Check #2: Middle Name Match (88.5%)
✅ Check #3: Last Name Match (94.2%)
✅ Check #4: Year Level Match (100%)
✅ Check #5: University Match (92.0%)
✅ Check #6: Document Keywords Found (90.0%)

Overall: 6/6 checks passed (93.5% average confidence)
Recommendation: Document validation successful
```

---

## 📚 EAF - Enrollment Assessment Form (6 Checks)

### What Gets Validated:
1. **First Name Match** - Student's first name found in EAF (≥80% confidence)
2. **Middle Name Match** - Student's middle name found in EAF (≥70% confidence)
   - Auto-pass if student has no middle name
3. **Last Name Match** - Student's last name found in EAF (≥80% confidence)
4. **Year Level Match** - Student's year level found in EAF
   - Looks for: "1st Year", "Freshman", "2nd Year", "Sophomore", etc.
5. **University Match** - Student's university name found in EAF (≥60% word match)
6. **Document Keywords Found** - EAF-related keywords present (≥3 keywords)
   - Looks for: "Enrollment", "Assessment", "Form", "Official", "Academic", "Student", "Tuition", "Fees", "Semester", "Registration", "Course", "Subject", "Grade", "Transcript", "Record", "University", "College", "School", "EAF", "Assessment Form", "Billing", "Statement", "Certificate"

### Success Criteria:
- ✅ **PASS:** 4 or more checks pass
- ✅ **PASS:** 3+ checks pass with 80%+ average confidence
- ❌ **FAIL:** Less than 3 checks pass OR low confidence

### Example Display:
```
✅ Check #1: First Name Match (94.5%)
✅ Check #2: Middle Name Match (85.0%)
✅ Check #3: Last Name Match (96.2%)
✅ Check #4: Year Level Match (100%)
✅ Check #5: University Match (88.0%)
✅ Check #6: Document Keywords Found (92.5%)

Overall: 6/6 checks passed (92.7% average confidence)
Recommendation: Document validation successful
```

---

## 📊 Grades Document (No Identity Verification)

### What Gets Validated:
- ⚠️ **NO identity verification performed**
- Only grade data extraction and OCR confidence tracking
- Admin view shows only OCR confidence percentage, not detailed checks

### Example Display:
```
OCR Confidence: 87.5%

Note: Grades documents do not undergo identity verification.
Please manually verify that the grades belong to the correct student.
```

---

## Common Validation Issues

### ❌ Low Confidence Scores
**Possible Causes:**
- Poor image quality (blurry, dark, low resolution)
- Document not properly aligned
- Text too small or unclear
- Handwritten text instead of printed

**Solution:** Request student to reupload with better quality

### ❌ Name Mismatch
**Possible Causes:**
- Student used nickname in registration
- Name spelled differently on document
- OCR misread the name

**Solution:** Manually verify and approve if name is similar

### ❌ Missing Required Elements
**Possible Causes:**
- Document cropped incorrectly
- Required headers/logos not visible
- Wrong document type uploaded

**Solution:** Request correct document type or better photo

---

## Color Coding in Admin Interface

- 🟢 **Green Card** = Check PASSED (confidence ≥ threshold)
- 🔴 **Red Card** = Check FAILED (confidence < threshold)
- 🔵 **Blue Badge** = Confidence percentage

---

## When to Manually Approve

Even if some checks fail, you can manually approve if:
1. ✅ Document is clearly authentic
2. ✅ Student identity is verified through other means
3. ✅ Failure is due to OCR limitation, not fraudulent document
4. ✅ At least 2-3 major checks passed (name, barangay/university)

---

## When to Reject

Reject document if:
1. ❌ Multiple critical checks failed (names don't match)
2. ❌ Document appears tampered or fake
3. ❌ Wrong document type uploaded
4. ❌ Document quality is too poor to read
5. ❌ Document belongs to different person

---

## Quick Decision Matrix

| Checks Passed | Average Confidence | Auto Status | Recommended Action |
|---------------|-------------------|-------------|-------------------|
| 4-6/6 (ID/EAF) | ≥80% | ✅ PASS | Approve |
| 3/6 (ID/EAF) | ≥80% | ✅ PASS | Approve |
| 3/6 (ID/EAF) | <80% | ⚠️ REVIEW | Manual review |
| 4-5/5 (Cert) | ≥75% | ✅ PASS | Approve |
| 3/5 (Cert) | ≥75% | ✅ PASS | Approve |
| 3/5 (Cert) | <75% | ⚠️ REVIEW | Manual review |
| 3-4/4 (Letter) | ≥75% | ✅ PASS | Approve |
| 2/4 (Letter) | ≥75% | ✅ PASS | Approve |
| 2/4 (Letter) | <75% | ⚠️ REVIEW | Manual review |
| <2/4 or <3/5 or <3/6 | Any | ❌ FAIL | Reject/Request reupload |

---

## Tips for Admins

1. **Always check both confidence scores AND visual inspection**
2. **High confidence doesn't guarantee authenticity** - manually verify suspicious documents
3. **Low confidence doesn't always mean fake** - poor photo quality can cause low scores
4. **Look for consistency** - if one document passes but another fails, investigate
5. **Use found text snippets** - verify that extracted text matches what you see in the document
6. **Trust your judgment** - OCR is a tool, not the final decision maker

---

## Accessing Validation Details

1. Go to **Manage Applicants** page
2. Find the applicant you want to review
3. Look for document rows in the applicant's detail section
4. Click **"View Validation"** button next to any document
5. Modal opens showing all validation checks with confidence scores
6. Review checks and make approval decision

---

## Troubleshooting

**"Detailed verification checks are not available for this document type"**
- Document was uploaded before validation system was implemented
- Request student to reupload the document
- Or manually review the document without validation data

**Some checks show 0% confidence**
- Information not found in document (e.g., no middle name on ID)
- OCR failed to extract that specific field
- Review the actual document to verify

**All checks passed but document looks suspicious**
- Validation passed doesn't mean document is authentic
- Always perform visual inspection
- Check for signs of tampering, photo manipulation, or fake documents
- When in doubt, request additional verification

---

**Last Updated:** October 18, 2025
**Version:** 2.0 - Standardized Validation Structure
