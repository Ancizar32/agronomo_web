const profileState = {signatureBase64: '', drawing: false, lastX: 0, lastY: 0};
const signatureCanvas = document.querySelector('#profile-signature-canvas');
const signatureCtx = signatureCanvas.getContext('2d');

async function profileApi(method, data = {}) {
  const response = await fetch('api/index.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({controller: 'agronomo', method, data}),
  });
  const raw = await response.text();
  let payload;
  try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró. Inicia sesión nuevamente.'); }
  if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible completar la operación.');
  return payload.detail;
}

function stripDataUriPrefix(value) {
  if (!value) return '';
  const commaIndex = value.indexOf(',');
  return value.startsWith('data:') && commaIndex !== -1 ? value.slice(commaIndex + 1) : value;
}

function toDataUri(value) {
  if (!value) return '';
  return value.startsWith('data:') ? value : `data:image/png;base64,${value}`;
}

function clearSignatureCanvas() {
  signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
}

function setSignatureFromValue(value) {
  clearSignatureCanvas();
  profileState.signatureBase64 = stripDataUriPrefix(value);
  if (!value) return;
  const image = new Image();
  image.onload = () => {
    const ratio = Math.min(signatureCanvas.width / image.width, signatureCanvas.height / image.height, 1);
    const width = image.width * ratio;
    const height = image.height * ratio;
    signatureCtx.drawImage(image, (signatureCanvas.width - width) / 2, (signatureCanvas.height - height) / 2, width, height);
  };
  image.src = toDataUri(value);
}

function canvasPoint(event) {
  const rect = signatureCanvas.getBoundingClientRect();
  const point = event.touches ? event.touches[0] : event;
  return {x: point.clientX - rect.left, y: point.clientY - rect.top};
}

function startDrawing(event) {
  event.preventDefault();
  profileState.drawing = true;
  const {x, y} = canvasPoint(event);
  profileState.lastX = x;
  profileState.lastY = y;
}

function drawMove(event) {
  if (!profileState.drawing) return;
  event.preventDefault();
  const {x, y} = canvasPoint(event);
  signatureCtx.strokeStyle = '#17231e';
  signatureCtx.lineWidth = 2.2;
  signatureCtx.lineCap = 'round';
  signatureCtx.beginPath();
  signatureCtx.moveTo(profileState.lastX, profileState.lastY);
  signatureCtx.lineTo(x, y);
  signatureCtx.stroke();
  profileState.lastX = x;
  profileState.lastY = y;
}

function stopDrawing() {
  if (!profileState.drawing) return;
  profileState.drawing = false;
  profileState.signatureBase64 = stripDataUriPrefix(signatureCanvas.toDataURL('image/png'));
}

signatureCanvas.addEventListener('mousedown', startDrawing);
signatureCanvas.addEventListener('mousemove', drawMove);
signatureCanvas.addEventListener('mouseup', stopDrawing);
signatureCanvas.addEventListener('mouseleave', stopDrawing);
signatureCanvas.addEventListener('touchstart', startDrawing, {passive: false});
signatureCanvas.addEventListener('touchmove', drawMove, {passive: false});
signatureCanvas.addEventListener('touchend', stopDrawing);

document.querySelector('#clear-signature-button').addEventListener('click', () => {
  clearSignatureCanvas();
  profileState.signatureBase64 = '';
});

document.querySelector('#signature-upload-input').addEventListener('change', (event) => {
  const file = event.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => setSignatureFromValue(reader.result);
  reader.readAsDataURL(file);
  event.target.value = '';
});

async function openProfileDialog() {
  const message = document.querySelector('#profile-message');
  message.textContent = '';
  document.querySelector('#profile-nombre').value = '';
  document.querySelector('#profile-titulo').value = '';
  document.querySelector('#profile-tarjeta').value = '';
  document.querySelector('#profile-celular').value = '';
  clearSignatureCanvas();
  profileState.signatureBase64 = '';
  document.querySelector('#profile-dialog').showModal();
  try {
    const config = await profileApi('getConfiguracionUsuarioWeb');
    if (config) {
      document.querySelector('#profile-nombre').value = config.nombre || '';
      document.querySelector('#profile-titulo').value = config.titulo || '';
      document.querySelector('#profile-tarjeta').value = config.tarjeta_profesional || '';
      document.querySelector('#profile-celular').value = config.celular || '';
      if (config.firma_base64) setSignatureFromValue(config.firma_base64);
    }
  } catch (error) {
    notifyResult(error.message, false);
  }
}

document.querySelector('#open-profile-button').addEventListener('click', openProfileDialog);

document.querySelector('#profile-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = document.querySelector('#profile-message');
  message.textContent = '';
  try {
    await profileApi('saveConfiguracionUsuarioWeb', {
      nombre: document.querySelector('#profile-nombre').value,
      titulo: document.querySelector('#profile-titulo').value,
      tarjeta_profesional: document.querySelector('#profile-tarjeta').value,
      celular: document.querySelector('#profile-celular').value,
      firma_base64: profileState.signatureBase64,
    });
    document.querySelector('#profile-dialog').close();
  } catch (error) {
    notifyResult(error.message, false);
  }
});
