<?php
/* filepath: c:\xampp\htdocs\EducAid\services\DocumentUploadService.php */
class DocumentUploadService {
    private $connection;
    private $uploadBaseDir;
    
    const REQUIRED_DOCUMENTS = [
        'eaf' => 'Enrollment Assessment Form',
        'letter_to_mayor' => 'Letter to Mayor', 
        'certificate_of_indigency' => 'Certificate of Indigency'
    ];
    
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    
    public function __construct($connection, $uploadBaseDir = '../../assets/uploads/students/') {
        $this->connection = $connection;
        $this->uploadBaseDir = $uploadBaseDir;
    }
    
    public function getUploadedDocuments($studentId) {
        $query = "SELECT type, file_path, uploaded_at FROM documents 
                  WHERE student_id = $1 AND type IN ('" . implode("','", array_keys(self::REQUIRED_DOCUMENTS)) . "')";
        $result = pg_query_params($this->connection, $query, [$studentId]);
        
        $uploaded = [];
        while ($row = pg_fetch_assoc($result)) {
            $uploaded[$row['type']] = $row;
        }
        return $uploaded;
    }
    
    public function isAllDocumentsUploaded($studentId) {
        $uploaded = $this->getUploadedDocuments($studentId);
        return count($uploaded) === count(self::REQUIRED_DOCUMENTS);
    }
    
    public function validateFile($file, $type) {
        $errors = [];
        
        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            $errors[] = "File size exceeds 5MB limit";
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $errors[] = "Only JPG, PNG, and PDF files are allowed";
        }
        
        // Check document type
        if (!array_key_exists($type, self::REQUIRED_DOCUMENTS)) {
            $errors[] = "Invalid document type";
        }
        
        return $errors;
    }
    
    public function uploadDocument($studentId, $studentName, $file, $type) {
        $errors = $this->validateFile($file, $type);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Create student directory
        $studentDir = $this->uploadBaseDir . $studentName . '/';
        if (!file_exists($studentDir)) {
            if (!mkdir($studentDir, 0755, true)) {
                return ['success' => false, 'errors' => ['Failed to create upload directory']];
            }
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $type . '_' . time() . '.' . $extension;
        $filePath = $studentDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'errors' => ['Failed to upload file']];
        }
        
        // Save to database
        $query = "INSERT INTO documents (student_id, type, file_path, uploaded_at) 
                  VALUES ($1, $2, $3, NOW())
                  ON CONFLICT (student_id, type) 
                  DO UPDATE SET file_path = EXCLUDED.file_path, uploaded_at = NOW()";
        
        $result = pg_query_params($this->connection, $query, [$studentId, $type, $filePath]);
        
        if ($result) {
            return ['success' => true, 'file_path' => $filePath];
        } else {
            unlink($filePath); // Remove file if database insert fails
            return ['success' => false, 'errors' => ['Database error']];
        }
    }
    
    public function getMissingDocuments($studentId) {
        $uploaded = $this->getUploadedDocuments($studentId);
        $missing = [];
        
        foreach (self::REQUIRED_DOCUMENTS as $type => $label) {
            if (!isset($uploaded[$type])) {
                $missing[$type] = $label;
            }
        }
        
        return $missing;
    }
}
?>