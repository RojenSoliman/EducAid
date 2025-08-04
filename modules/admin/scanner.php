<?php
// scanner.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Camera QR Code Scanner</title>
  <style>
    body {
      font-family: sans-serif;
      text-align: center;
      background: #f0f0f0;
      margin: 0; padding: 1rem;
    }
    h1 {
      margin-bottom: 1rem;
    }
    #reader {
      width: 300px;
      height: 300px;
      margin: 0 auto;
      border: 2px solid #ccc;
      background: white;
    }
    .controls {
      margin: 1rem 0;
    }
    button, select {
      padding: 0.5rem 1rem;
      margin: 0.5rem 0.5rem;
      font-size: 1rem;
      cursor: pointer;
    }
    #result {
      font-family: monospace;
      color: #333;
    }
  </style>
</head>
<body>
  <h1>QR Code Scanner</h1>

  <div id="reader"></div>

  <div class="controls">
    <select id="camera-select">
      <option value="">Select Camera</option>
    </select>
    <br />
    <button id="start-button">Start Scanner</button>
    <button id="stop-button" disabled>Stop Scanner</button>
  </div>

  <p><strong>Result:</strong> <span id="result">—</span></p>

  <script src="https://unpkg.com/html5-qrcode"></script>

  <script>
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    const resultSpan = document.getElementById('result');
    const cameraSelect = document.getElementById('camera-select');
    const html5QrCode = new Html5Qrcode("reader");

    let currentCameraId = null;

    // Populate camera dropdown on load
    Html5Qrcode.getCameras().then(cameras => {
      if (!cameras.length) {
        alert("No cameras found.");
        return;
      }

      cameras.forEach(camera => {
        const option = document.createElement('option');
        option.value = camera.id;
        option.text = camera.label || `Camera ${camera.id}`;
        cameraSelect.appendChild(option);
      });

      // Select back camera by default if available
      const backCam = cameras.find(cam => cam.label.toLowerCase().includes('back'));
      if (backCam) {
        cameraSelect.value = backCam.id;
        currentCameraId = backCam.id;
      } else {
        cameraSelect.selectedIndex = 1; // first available camera
        currentCameraId = cameras[0].id;
      }
    });

    cameraSelect.addEventListener('change', () => {
      currentCameraId = cameraSelect.value;
    });

    startButton.addEventListener('click', () => {
      if (!currentCameraId) {
        alert("Please select a camera.");
        return;
      }

      html5QrCode.start(
        currentCameraId,
        { fps: 10, qrbox: { width: 250, height: 250 } },
        decodedText => {
          resultSpan.textContent = decodedText;
          html5QrCode.stop();
          startButton.disabled = false;
          stopButton.disabled = true;
        },
        error => {
          // decode errors ignored
        }
      ).then(() => {
        startButton.disabled = true;
        stopButton.disabled = false;
      }).catch(err => {
        console.error("Failed to start scanning:", err);
      });
    });

    stopButton.addEventListener('click', () => {
      html5QrCode.stop()
        .then(() => {
          startButton.disabled = false;
          stopButton.disabled = true;
        })
        .catch(err => console.error("Failed to stop scanning:", err));
    });
  </script>
</body>
</html>
