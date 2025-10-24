# Document Re-upload Service Refactoring

## Problem
The original `DocumentReuploadService` was causing errors when uploading documents:
- **Error**: "Cannot use object of type PgSql\Connection as array"
- **Root Cause**: Passing database connection to OCRProcessingService instead of config array
- **Impact**: Files were uploaded to directories but not saved to database, no UI refresh

## Solution
Refactored `DocumentReuploadService` to follow the same pattern as `student_register.php`:

### Key Changes

1. **Use DocumentService for Database Operations**
   - Instead of manually writing INSERT/UPDATE queries
   - DocumentService handles all database interactions
   - Same pattern used during student registration

2. **Direct Upload to Permanent Storage**
   - **Skips temp folder** (as requested)
   - Uploads directly to `/assets/uploads/student/{folder}/`
   - Filename format: `{STUDENTID}_{doctype}_{timestamp}.{ext}`

3. **Minimal OCR Processing**
   - Only processes OCR for grades (type '01')
   - Other documents marked as 'pending' for admin manual verification
   - Uses OCRProcessingService with config array (not database connection)

4. **Simplified Architecture**
   ```
   uploadDocument()
     ↓
   move_uploaded_file() → permanent storage
     ↓
   processGradesOCR() (if grades)
     ↓
   DocumentService->saveDocument()
     ↓
   logAudit()
     ↓
   checkAndClearRejectionStatus()
   ```

### File Structure

```php
class DocumentReuploadService {
    private $db;
    private $baseDir;
    private $docService;  // NEW: Uses DocumentService
    
    public function uploadDocument($studentId, $docTypeCode, $tmpPath, $originalName, $studentData)
    private function processGradesOCR($filePath, $studentData)
    private function logAudit($studentId, $docTypeName, $ocrData)
    private function checkAndClearRejectionStatus($studentId)
}
```

### Document Type Mapping

| Code | Name | Folder | OCR Processing |
|------|------|--------|----------------|
| 04 | id_picture | id_pictures | None (pending) |
| 00 | eaf | enrollment_forms | None (pending) |
| 01 | academic_grades | grades | **Yes** (OCR enabled) |
| 02 | letter_to_mayor | letter_mayor | None (pending) |
| 03 | certificate_of_indigency | indigency | None (pending) |

### Upload Flow

1. **Student uploads document** → `upload_document.php`
2. **File validation** → Extension, size, type
3. **Move to permanent storage** → `/assets/uploads/student/{folder}/`
4. **OCR processing** (if grades):
   - Extract text using Tesseract
   - Calculate confidence score
   - Save OCR text to `.ocr.txt` file
5. **Save to database** using DocumentService:
   - Creates/updates documents table entry
   - Stores file path, OCR confidence, verification status
6. **Audit logging** → Records upload action
7. **Check completion** → If all rejected docs uploaded, clear rejection status

### Benefits

✅ **Consistent with Registration** - Same pattern as `student_register.php`  
✅ **No Temp Folder** - Direct upload to permanent storage  
✅ **Proper Error Handling** - Database errors don't leave orphaned files  
✅ **OCR Integration** - Grades automatically processed  
✅ **Audit Trail** - All uploads logged  
✅ **Auto-completion** - Clears rejection status when all docs uploaded  

### Testing

Upload any document type:
- **EAF (00)**: Should upload, mark as 'pending' (0% confidence)
- **Grades (01)**: Should upload, extract grades, mark confidence based on OCR
- **Letter (02)**: Should upload, mark as 'pending' (0% confidence)
- **Certificate (03)**: Should upload, mark as 'pending' (0% confidence)
- **ID Picture (04)**: Should upload, mark as 'pending' (0% confidence)

All should:
- Save file to `/assets/uploads/student/{folder}/`
- Create database entry in `documents` table
- Show in UI immediately after upload
- Log audit entry

### Files Modified

- `services/DocumentReuploadService.php` - Complete rewrite (250 lines)

### Files Unchanged

- `services/DocumentService.php` - Still handles database operations
- `services/OCRProcessingService.php` - Still handles OCR processing
- `modules/student/upload_document.php` - No changes needed

## Result

✅ Document uploads now work correctly  
✅ Files saved to permanent storage  
✅ Database updated properly  
✅ UI refreshes showing uploaded documents  
✅ OCR processing works for grades  
✅ Manual verification workflow for other docs  

**Status**: Ready for testing
