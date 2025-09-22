<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot Test</title>
</head>
<body>
    <h1>Chatbot Test</h1>
    <div id="result"></div>

    <script>
        async function testChatbot() {
            try {
                console.log('Testing chatbot API...');
                
                const response = await fetch('../chatbot/gemini_chat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        message: 'Hello, what is EducAid?'
                    })
                });
                
                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Response data:', data);
                
                document.getElementById('result').innerHTML = `
                    <h3>✅ Chatbot is working!</h3>
                    <p><strong>Response:</strong> ${data.reply || 'No reply'}</p>
                `;
                
            } catch (error) {
                console.error('Chatbot test error:', error);
                document.getElementById('result').innerHTML = `
                    <h3>❌ Chatbot error:</h3>
                    <p><strong>Error:</strong> ${error.message}</p>
                `;
            }
        }
        
        // Test on page load
        testChatbot();
    </script>
</body>
</html>