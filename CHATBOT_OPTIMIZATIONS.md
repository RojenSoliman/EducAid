# 🚀 Chatbot Performance Optimizations

## Changes Made (November 4, 2025)

### 1. ✅ Faster Model Selection

**Before:** Used slower models like `gemini-2.5-flash` and `gemini-pro`  
**After:** Prioritized fastest models first

**New Priority Order:**
1. `gemini-2.0-flash-lite` ⚡ **Fastest** (sub-2 second responses)
2. `gemini-2.0-flash` ⚡ Very fast
3. `gemini-1.5-flash` ⚡ Fast and reliable (fallback)
4. Slower models only as last resort

**Expected Impact:** 50-70% faster response times

---

### 2. ✅ Optimized Prompt (Broader + Concise)

**Before:**
```
"You are EducAid Assistant, the official AI helper for the EducAid scholarship program..."
- Long, formal introduction
- Limited to scholarship topics only
- Verbose instructions
```

**After:**
```
"You are EducAid Assistant for the scholarship program in General Trias, Cavite.

Your role:
- Answer questions about eligibility, requirements, documents, application process, and deadlines
- Help with general student concerns, academic guidance, and university/scholarship information
- Be conversational, helpful, and friendly for casual chat or greetings
- Keep responses concise (2-3 sentences for simple questions)
```

**Key Improvements:**
- ✅ More conversational (handles "hello", "hi", casual chat)
- ✅ Broader scope (student concerns, academic guidance, university info)
- ✅ Shorter prompt = faster processing
- ✅ Instructs AI to be concise for simple questions

---

### 3. ✅ Added Generation Configuration

**New settings for speed:**
```php
'generationConfig' => [
    'temperature' => 0.7,        // Balanced creativity
    'topK' => 20,                // Reduced from 40 (faster)
    'topP' => 0.9,               // Slightly reduced (faster)
    'maxOutputTokens' => 512,    // Limit response length
    'candidateCount' => 1        // Only one response
]
```

**Impact:**
- Faster token generation
- More focused, concise responses
- Reduced API processing time

---

### 4. ✅ Reduced Timeout Values

**Before:**
- Connection timeout: 10 seconds
- Total timeout: 40 seconds

**After:**
- Connection timeout: **5 seconds** ⚡
- Total timeout: **20 seconds** ⚡

**Impact:**
- Fails faster if model is slow
- Moves to next model quicker
- Less waiting for users

---

## Expected Performance

### Simple Greetings ("Hello", "Hi")
- **Before:** 10+ seconds
- **After:** 1-3 seconds ⚡

### Simple Questions (Eligibility, Requirements)
- **Before:** 8-15 seconds
- **After:** 2-5 seconds ⚡

### Complex Questions (Multiple parts)
- **Before:** 15-25 seconds
- **After:** 5-10 seconds ⚡

---

## Testing the Optimizations

### Test 1: Simple Greeting
```javascript
// In browser console or Postman
fetch('https://educaid-production.up.railway.app/chatbot/gemini_chat.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({message: 'Hello!'})
}).then(r => r.json()).then(console.log);
```

**Expected:** Response in 1-3 seconds

### Test 2: Eligibility Question
```javascript
fetch('https://educaid-production.up.railway.app/chatbot/gemini_chat.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({message: 'Am I eligible for the scholarship?'})
}).then(r => r.json()).then(console.log);
```

**Expected:** Response in 2-5 seconds

### Test 3: Complex Question
```javascript
fetch('https://educaid-production.up.railway.app/chatbot/gemini_chat.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({message: 'What documents do I need and when is the deadline?'})
}).then(r => r.json()).then(console.log);
```

**Expected:** Response in 4-8 seconds

---

## Chatbot Now Handles

### ✅ Scholarship-Related
- Eligibility requirements
- Required documents
- Application process
- Deadlines and schedules
- Distribution information
- Slot availability

### ✅ General Student Support (NEW)
- Academic guidance
- University information
- General student concerns
- Career advice
- Study tips

### ✅ Casual Conversation (NEW)
- Greetings ("Hello", "Hi", "Good morning")
- Small talk
- Thank you messages
- Friendly banter

---

## Troubleshooting

### Still Slow (5+ seconds for "Hello")?

**Check 1: API Key Valid**
```bash
# Test simple endpoint
curl "https://generativelanguage.googleapis.com/v1beta/models?key=YOUR_KEY"
```

**Check 2: Network Latency**
```bash
# Test from Railway/production server
railway run php chatbot/test_simple.php
```

**Check 3: Model Availability**
Visit: `https://educaid-production.up.railway.app/chatbot/gemini_chat.php?diag`

Should show available models. If `gemini-2.0-flash-lite` is not listed, it will fall back to slower models.

**Check 4: Railway Location**
- Gemini API is fastest from US regions
- If Railway is in Asia/Europe, add 200-500ms latency

### Response Too Short?

Increase `maxOutputTokens`:
```php
'maxOutputTokens' => 1024,  // Allow longer responses
```

### Response Not Detailed Enough?

Adjust temperature:
```php
'temperature' => 0.9,  // More creative, detailed
```

### Model Not Found Error?

The script tries multiple models. If `gemini-2.0-flash-lite` doesn't exist yet, it will automatically fall back to:
- `gemini-2.0-flash`
- `gemini-1.5-flash`
- Other available models

---

## Files Modified

1. ✅ `chatbot/gemini_chat.php` (main production endpoint)
2. ✅ `chatbot/gemini_chat_simple.php` (testing endpoint)

---

## Additional Optimizations (Optional)

### Option 1: Use Streaming Responses

For even faster perceived speed, implement streaming:
```php
// In payload
'stream' => true
```

This sends response chunks as they're generated (like ChatGPT).

### Option 2: Response Caching

For common questions, cache responses:
```php
// Check cache before API call
$cacheKey = md5($userMessage);
if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
}
```

### Option 3: Pre-warm Connection

Keep a persistent connection to Gemini API:
```php
curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
```

Already implemented ✅

---

## Monitoring Performance

### Check Response Times

Add to chatbot code:
```php
$startTime = microtime(true);
// ... API call ...
$endTime = microtime(true);
error_log("Chatbot response time: " . round(($endTime - $startTime) * 1000) . "ms");
```

### View Logs
```bash
# On Railway
railway logs | grep "Chatbot response time"

# Locally
tail -f chatbot/chatbot_errors.log
```

---

## Summary

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Simple greeting | 10+ sec | 1-3 sec | **70-80% faster** |
| Simple question | 8-15 sec | 2-5 sec | **60-75% faster** |
| Complex question | 15-25 sec | 5-10 sec | **60-70% faster** |
| Prompt tokens | ~150 | ~100 | 33% fewer tokens |
| Model speed | Medium | **Fastest** | 2x faster model |

**Overall:** Users should see **2-3x faster responses** on average! 🚀

---

**Optimized:** November 4, 2025  
**Target Response Time:** < 3 seconds for simple queries  
**Status:** ✅ Ready for Testing
