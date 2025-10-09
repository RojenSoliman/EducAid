#!/bin/bash
# OCR Grade Processing Test Script
# Usage: ./test_ocr.sh <image_file>

set -e

# Configuration
TESSERACT_PATH="tesseract"
TEMP_DIR="./temp_ocr"
DPI=350

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Create temp directory
mkdir -p "$TEMP_DIR"

# Function to log with timestamp
log() {
    echo -e "${BLUE}[$(date '+%H:%M:%S')]${NC} $1"
}

success() {
    echo -e "${GREEN}✓${NC} $1"
}

warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

error() {
    echo -e "${RED}✗${NC} $1"
}

# Check if file provided
if [ $# -eq 0 ]; then
    error "Please provide an image file"
    echo "Usage: $0 <image_file>"
    exit 1
fi

INPUT_FILE="$1"
if [ ! -f "$INPUT_FILE" ]; then
    error "File not found: $INPUT_FILE"
    exit 1
fi

# Check if tesseract is installed
if ! command -v "$TESSERACT_PATH" &> /dev/null; then
    error "Tesseract not found. Please install tesseract-ocr"
    exit 1
fi

# Get file info
FILE_EXT="${INPUT_FILE##*.}"
BASENAME=$(basename "$INPUT_FILE" ".$FILE_EXT")
OUTPUT_BASE="$TEMP_DIR/${BASENAME}_processed"

log "Processing: $INPUT_FILE"
log "File type: $FILE_EXT"

# Preprocess image if needed (requires ImageMagick)
PROCESSED_FILE="$INPUT_FILE"
if command -v convert &> /dev/null; then
    log "Preprocessing image with ImageMagick..."
    PROCESSED_FILE="$OUTPUT_BASE.png"
    
    # Enhanced preprocessing for better OCR
    convert "$INPUT_FILE" \
        -density $DPI \
        -colorspace Gray \
        -normalize \
        -contrast \
        -sharpen 0x1 \
        -threshold 50% \
        "$PROCESSED_FILE"
    
    success "Image preprocessed: $PROCESSED_FILE"
else
    warning "ImageMagick not found - using original image"
fi

# Run Tesseract OCR with TSV output
log "Running Tesseract OCR..."
TSV_FILE="${OUTPUT_BASE}.tsv"
TXT_FILE="${OUTPUT_BASE}.txt"

# TSV output for structured data
$TESSERACT_PATH "$PROCESSED_FILE" "${OUTPUT_BASE}" -l eng --oem 1 --psm 6 tsv 2>/dev/null || {
    error "Tesseract OCR failed"
    exit 1
}

# Plain text output for readability
$TESSERACT_PATH "$PROCESSED_FILE" "${OUTPUT_BASE}_text" -l eng --oem 1 --psm 6 2>/dev/null || {
    warning "Plain text OCR failed"
}

success "OCR completed successfully"

# Display results
if [ -f "${TSV_FILE}" ]; then
    echo
    log "TSV Output (first 10 lines with confidence > 50):"
    echo -e "${BLUE}Level\tPage\tBlock\tPar\tLine\tWord\tLeft\tTop\tWidth\tHeight\tConf\tText${NC}"
    head -1 "${TSV_FILE}" > /dev/null
    tail -n +2 "${TSV_FILE}" | awk -F'\t' '$11 > 50 && $12 != "" {print $1"\t"$2"\t"$3"\t"$4"\t"$5"\t"$6"\t"$7"\t"$8"\t"$9"\t"$10"\t"$11"\t"$12}' | head -10
    
    echo
    log "Grade-like patterns found:"
    tail -n +2 "${TSV_FILE}" | awk -F'\t' '$11 > 70 && $12 ~ /^[1-5]\.[0-9]{2}$|^[0-4]\.[0-9]+$|^[89][0-9]$|^[A-D][+-]?$/ {print "  " $12 " (confidence: " $11 "%)"}' | sort -u
fi

if [ -f "${OUTPUT_BASE}_text.txt" ]; then
    echo
    log "Plain text output:"
    head -20 "${OUTPUT_BASE}_text.txt" | while read line; do
        echo "  $line"
    done
fi

# Parse TSV for subjects and grades
echo
log "Parsing subjects and grades..."

# Simple PHP parser for testing
php -r "
\$tsv = file_get_contents('${TSV_FILE}');
\$lines = explode(PHP_EOL, \$tsv);
array_shift(\$lines); // Remove header

\$subjects = [];
\$currentLine = null;
\$lineWords = [];

foreach (\$lines as \$line) {
    if (empty(trim(\$line))) continue;
    
    \$cols = explode(\"\t\", \$line);
    if (count(\$cols) < 12) continue;
    
    \$level = \$cols[0];
    \$pageNum = \$cols[1];
    \$blockNum = \$cols[2];
    \$parNum = \$cols[3];
    \$lineNum = \$cols[4];
    \$wordNum = \$cols[5];
    \$left = intval(\$cols[6]);
    \$top = intval(\$cols[7]);
    \$width = intval(\$cols[8]);
    \$height = intval(\$cols[9]);
    \$conf = intval(\$cols[10]);
    \$text = trim(\$cols[11]);
    
    if (empty(\$text) || \$conf < 30) continue;
    
    \$lineKey = \"\$pageNum-\$blockNum-\$parNum-\$lineNum\";
    
    if (\$currentLine !== \$lineKey) {
        // Process previous line
        if (\$currentLine && count(\$lineWords) > 1) {
            \$lineText = implode(' ', array_column(\$lineWords, 'text'));
            \$grades = array_filter(\$lineWords, function(\$w) {
                return preg_match('/^[1-5]\.[0-9]{2}$|^[0-4]\.[0-9]+$|^[89][0-9]$|^[A-D][+-]?$/i', \$w['text']);
            });
            
            if (!empty(\$grades) && !preg_match('/subject|course|grade|semester|year/i', \$lineText)) {
                \$grade = end(\$grades);
                \$subjectText = trim(str_replace(\$grade['text'], '', \$lineText));
                if (strlen(\$subjectText) > 3) {
                    \$subjects[] = [
                        'subject' => \$subjectText,
                        'grade' => \$grade['text'],
                        'confidence' => \$grade['conf'],
                        'line' => \$lineText
                    ];
                }
            }
        }
        
        \$currentLine = \$lineKey;
        \$lineWords = [];
    }
    
    \$lineWords[] = ['text' => \$text, 'left' => \$left, 'conf' => \$conf];
}

echo \"Found subjects with grades:\\n\";
foreach (\$subjects as \$i => \$subject) {
    printf(\"  %d. %s: %s (conf: %d%%)\\n\", 
        \$i + 1, 
        \$subject['subject'], 
        \$subject['grade'], 
        \$subject['confidence']
    );
}

if (empty(\$subjects)) {
    echo \"  No subjects with grades detected.\\n\";
}
"

# Cleanup
echo
log "Cleaning up temporary files..."
rm -f "$PROCESSED_FILE" 2>/dev/null || true
success "Test completed"

echo
echo -e "${GREEN}Files generated:${NC}"
echo "  - TSV: ${TSV_FILE}"
[ -f "${OUTPUT_BASE}_text.txt" ] && echo "  - Text: ${OUTPUT_BASE}_text.txt"
echo
echo -e "${BLUE}To test with different settings:${NC}"
echo "  - PSM modes: --psm 4 (single column), --psm 6 (uniform block), --psm 7 (single text line)"
echo "  - OEM modes: --oem 1 (LSTM), --oem 2 (Legacy + LSTM), --oem 3 (default)"
echo "  - Languages: -l eng+fil (for Filipino text)"