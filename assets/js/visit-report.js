// Genera el mismo "Informe Técnico de Visita a Campo" que produce la app
// móvil (ver agronomo_app/lib/screens/visita_tecnica/visita_tecnica_pdf_screen.dart),
// pero desde el panel web, reutilizando los datos que visits.js ya cargó
// para la visita seleccionada (visitState.detail).

const REPORT_PAGE_W = 595.28; // A4 en puntos
const REPORT_PAGE_H = 841.89;
const REPORT_MARGIN = 46;
const REPORT_CONTENT_W = REPORT_PAGE_W - REPORT_MARGIN * 2;
const REPORT_FOOTER_TOP = REPORT_PAGE_H - 50;

const REPORT_COLORS = {
  forest: '#173f32',
  lime: '#d8f36a',
  ink: '#17231e',
  muted: '#657069',
  cream: '#fbfaf4',
  border: '#d8d8ca',
};

async function reportFetchDataUrl(url) {
  const response = await fetch(url, {cache: 'no-store'});
  if (!response.ok) throw new Error(`No fue posible cargar ${url}`);
  const blob = await response.blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error(`No fue posible leer ${url}`));
    reader.readAsDataURL(blob);
  });
}

async function reportFetchFirmaTecnico(usuarioId) {
  if (!usuarioId) return null;
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({controller: 'agronomo', method: 'getFirmaTecnicoWeb', data: {usuario_id: usuarioId}}),
    });
    const payload = await response.json();
    return payload.success === true ? payload.detail : null;
  } catch (_) {
    return null;
  }
}

function reportImageSize(doc, dataUrl, maxWidth, maxHeight) {
  const props = doc.getImageProperties(dataUrl);
  const ratio = props.width / props.height;
  let width = maxWidth;
  let height = width / ratio;
  if (height > maxHeight) {
    height = maxHeight;
    width = height * ratio;
  }
  return {width, height, format: props.fileType};
}

class VisitReportBuilder {
  constructor(doc) {
    this.doc = doc;
    this.y = REPORT_MARGIN;
  }

  newPage() {
    this.doc.addPage();
    this.y = REPORT_MARGIN;
  }

  ensureSpace(needed) {
    if (this.y + needed > REPORT_FOOTER_TOP) this.newPage();
  }

  heading(text, {size = 14, color = REPORT_COLORS.forest, gapBefore = 10, gapAfter = 6} = {}) {
    this.ensureSpace(size + gapBefore + gapAfter);
    this.y += gapBefore;
    this.doc.setFont('times', 'bold');
    this.doc.setFontSize(size);
    this.doc.setTextColor(color);
    this.doc.text(text, REPORT_MARGIN, this.y);
    this.y += gapAfter + size * 0.3;
  }

  paragraph(text, {size = 10, color = REPORT_COLORS.ink, bold = false, italic = false, gapAfter = 4} = {}) {
    if (!text) return;
    this.doc.setFont('helvetica', bold ? 'bold' : italic ? 'italic' : 'normal');
    this.doc.setFontSize(size);
    this.doc.setTextColor(color);
    const lines = this.doc.splitTextToSize(text, REPORT_CONTENT_W);
    const lineHeight = size * 1.35;
    lines.forEach((line) => {
      this.ensureSpace(lineHeight);
      this.doc.text(line, REPORT_MARGIN, this.y);
      this.y += lineHeight;
    });
    this.y += gapAfter;
  }

  bullets(items, {size = 10, color = REPORT_COLORS.ink} = {}) {
    items.forEach((item) => this.paragraph(`•  ${item}`, {size, color, gapAfter: 2}));
    this.y += 4;
  }

  // Etiqueta pequeña en versalitas — encabezados discretos de sección
  // (ej. "FINCA", "OBJETIVO DE LA VISITA"), estilo ficha técnica formal.
  eyebrow(text, {color = REPORT_COLORS.muted, gapAfter = 3} = {}) {
    this.doc.setFont('helvetica', 'bold');
    this.doc.setFontSize(8.5);
    this.doc.setTextColor(color);
    this.doc.text(text.toUpperCase(), REPORT_MARGIN, this.y, {charSpace: 0.5});
    this.y += gapAfter + 8.5;
  }

  // Encabezado de sección numerado (01 Hallazgo, 02 Actividades, ...) con
  // una línea delgada debajo — mismo lenguaje visual que el informe móvil y
  // que las secciones numeradas de la propia app web.
  sectionHeading(numero, text, {gapBefore = 4} = {}) {
    this.ensureSpace(24);
    this.y += gapBefore;
    const badgeSize = 13;
    this.doc.setFillColor(REPORT_COLORS.forest);
    this.doc.circle(REPORT_MARGIN + badgeSize / 2, this.y - badgeSize / 3, badgeSize / 2, 'F');
    this.doc.setFont('helvetica', 'bold');
    this.doc.setFontSize(7);
    this.doc.setTextColor(REPORT_COLORS.cream);
    this.doc.text(numero, REPORT_MARGIN + badgeSize / 2, this.y - badgeSize / 3 + 2.4, {align: 'center'});
    this.doc.setFont('helvetica', 'bold');
    this.doc.setFontSize(10.5);
    this.doc.setTextColor(REPORT_COLORS.ink);
    this.doc.text(text.toUpperCase(), REPORT_MARGIN + badgeSize + 8, this.y, {charSpace: 0.3});
    this.y += 6;
    this.doc.setDrawColor(REPORT_COLORS.border);
    this.doc.line(REPORT_MARGIN, this.y, REPORT_PAGE_W - REPORT_MARGIN, this.y);
    this.y += 12;
  }

  // Ficha con los datos principales de la visita, como un panel de datos en
  // vez de líneas sueltas de texto — igual que en el informe móvil.
  metaPanel(columns) {
    const boxH = 48;
    this.ensureSpace(boxH + 10);
    const colW = REPORT_CONTENT_W / columns.length;
    this.doc.setFillColor(REPORT_COLORS.cream);
    this.doc.setDrawColor(REPORT_COLORS.border);
    this.doc.roundedRect(REPORT_MARGIN, this.y, REPORT_CONTENT_W, boxH, 4, 4, 'FD');
    columns.forEach(([label, value], index) => {
      const x = REPORT_MARGIN + 14 + index * colW;
      this.doc.setFont('helvetica', 'bold');
      this.doc.setFontSize(8);
      this.doc.setTextColor(REPORT_COLORS.muted);
      this.doc.text(label.toUpperCase(), x, this.y + 18, {charSpace: 0.4});
      this.doc.setFont('helvetica', 'bold');
      this.doc.setFontSize(11.5);
      this.doc.setTextColor(REPORT_COLORS.ink);
      this.doc.text(String(value || '—'), x, this.y + 33, {maxWidth: colW - 16});
    });
    this.y += boxH + 16;
  }

  // Panel destacado (borde de acento a la izquierda) para el objetivo u
  // observación general de la visita.
  calloutPanel(label, text) {
    if (!text) return;
    this.doc.setFont('helvetica', 'normal');
    this.doc.setFontSize(10.5);
    const lines = this.doc.splitTextToSize(text, REPORT_CONTENT_W - 28);
    const height = 20 + lines.length * 14;
    this.ensureSpace(height + 12);
    this.doc.setFillColor(REPORT_COLORS.cream);
    this.doc.rect(REPORT_MARGIN, this.y, REPORT_CONTENT_W, height, 'F');
    this.doc.setFillColor(REPORT_COLORS.lime);
    this.doc.rect(REPORT_MARGIN, this.y, 3, height, 'F');
    this.doc.setFont('helvetica', 'bold');
    this.doc.setFontSize(8.5);
    this.doc.setTextColor(REPORT_COLORS.forest);
    this.doc.text(label.toUpperCase(), REPORT_MARGIN + 14, this.y + 15, {charSpace: 0.5});
    this.doc.setFont('helvetica', 'normal');
    this.doc.setFontSize(10.5);
    this.doc.setTextColor(REPORT_COLORS.ink);
    this.doc.text(lines, REPORT_MARGIN + 14, this.y + 30);
    this.y += height + 12;
  }

  divider() {
    this.ensureSpace(16);
    this.y += 6;
    this.doc.setDrawColor(REPORT_COLORS.border);
    this.doc.line(REPORT_MARGIN, this.y, REPORT_PAGE_W - REPORT_MARGIN, this.y);
    this.y += 10;
  }

  async image(dataUrl, {maxHeight = 220, center = true} = {}) {
    if (!dataUrl) return;
    try {
      const {width, height} = reportImageSize(this.doc, dataUrl, REPORT_CONTENT_W * 0.7, maxHeight);
      this.ensureSpace(height + 10);
      const x = center ? REPORT_MARGIN + (REPORT_CONTENT_W - width) / 2 : REPORT_MARGIN;
      this.doc.addImage(dataUrl, x, this.y, width, height);
      this.y += height + 10;
    } catch (_) {
      // Imagen corrupta o formato no soportado: se omite sin interrumpir el reporte.
    }
  }
}

async function buildVisitReportPdf() {
  const {jsPDF} = window.jspdf;
  const detail = visitState.detail;
  const {visita, lotes = [], hallazgos = [], actividades = [], formulas = [], recomendaciones = []} = detail;
  const activeFormulas = formulas.filter((f) => f.es_header !== '1');

  const doc = new jsPDF({unit: 'pt', format: 'a4'});
  const report = new VisitReportBuilder(doc);
  const year = new Date().getFullYear();
  const fecha = formatVisitDate(visita.fecha);

  const [logoDataUrl, firma] = await Promise.all([
    reportFetchDataUrl('assets/img/logo.png').catch(() => null),
    reportFetchFirmaTecnico(visita.created_by),
  ]);

  // ===================== Portada =====================
  // El logo trae texto fino (marca + eslogan "El campo, en manos expertas")
  // dentro de la propia imagen: a 54pt quedaba ilegible. 110pt lo mantiene
  // nítido sin desbordar la cabecera.
  const logoMaxSize = 110;
  const coverTop = report.y;
  let coverTextX = REPORT_MARGIN;
  if (logoDataUrl) {
    try {
      const {width, height} = reportImageSize(doc, logoDataUrl, logoMaxSize, logoMaxSize);
      doc.addImage(logoDataUrl, REPORT_MARGIN, coverTop, width, height);
      coverTextX = REPORT_MARGIN + width + 14;
    } catch (_) {
      // Logo no disponible: el bloque de título ocupa todo el ancho.
    }
  }
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(8.5);
  doc.setTextColor(REPORT_COLORS.forest);
  doc.text('INFORME TÉCNICO AGRONÓMICO', coverTextX, coverTop + 50, {charSpace: 0.6});
  doc.setFont('times', 'bold');
  doc.setFontSize(25);
  doc.setTextColor(REPORT_COLORS.ink);
  doc.text('Visita a campo', coverTextX, coverTop + 76);
  report.y = coverTop + logoMaxSize + 16;

  doc.setDrawColor(REPORT_COLORS.border);
  doc.line(REPORT_MARGIN, report.y, REPORT_PAGE_W - REPORT_MARGIN, report.y);
  report.y += 22;

  report.metaPanel([
    ['Finca', visita.finca_nombre || 'Sin nombre'],
    ['Fecha de visita', fecha],
    ['Técnico responsable', visita.tecnico_nombre || 'Sin técnico'],
  ]);

  if (visita.descripcion && visita.descripcion.trim()) {
    report.calloutPanel('Descripción de la visita', visita.descripcion.trim());
  }

  report.heading('Resumen ejecutivo', {size: 15});
  report.paragraph('Cobertura de la visita registrada en campo, por lote.', {
    size: 9, color: REPORT_COLORS.muted, gapAfter: 12,
  });

  const stats = [
    [String(lotes.length), 'Lotes visitados'],
    [String(hallazgos.length), 'Hallazgos'],
    [String(actividades.length), 'Actividades'],
    [String(activeFormulas.length), 'Fórmulas aplicadas'],
    [String(recomendaciones.length), 'Recomendaciones'],
  ];
  const boxW = 95;
  const boxH = 60;
  const gap = 10;
  report.ensureSpace(boxH + 10);
  stats.forEach(([value, label], index) => {
    const x = REPORT_MARGIN + index * (boxW + gap);
    doc.setFillColor(REPORT_COLORS.cream);
    doc.setDrawColor(REPORT_COLORS.border);
    doc.roundedRect(x, report.y, boxW, boxH, 4, 4, 'FD');
    doc.setFont('times', 'bold');
    doc.setFontSize(22);
    doc.setTextColor(REPORT_COLORS.forest);
    doc.text(value, x + boxW / 2, report.y + 30, {align: 'center'});
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(REPORT_COLORS.muted);
    const labelLines = doc.splitTextToSize(label, boxW - 10);
    doc.text(labelLines, x + boxW / 2, report.y + 42, {align: 'center'});
  });
  report.y += boxH + 10;

  // ===================== Reporte por lote =====================
  report.newPage();
  report.eyebrow('Reporte por lote · Visita técnica', {color: REPORT_COLORS.forest, gapAfter: 4});
  doc.setFont('times', 'bold');
  doc.setFontSize(17);
  doc.setTextColor(REPORT_COLORS.ink);
  doc.text(`${visita.finca_nombre || 'Sin nombre'} — ${fecha}`, REPORT_MARGIN, report.y);
  report.y += 12;
  report.divider();

  for (const lote of lotes) {
    const lotHallazgos = hallazgos.filter((h) => h.visita_detalle_id === lote.id);
    const lotActividades = actividades.filter((a) => a.visita_detalle_id === lote.id);
    const lotFormulas = formulas.filter((f) => f.visita_detalle_id === lote.id && f.es_header !== '1');
    const lotFormulaHeaders = formulas.filter((f) => f.visita_detalle_id === lote.id && f.es_header === '1');
    const lotRecomendaciones = recomendaciones.filter((r) => r.visita_detalle_id === lote.id);
    const sinNovedades = !lotHallazgos.length && !lotActividades.length && !lotFormulas.length && !lotRecomendaciones.length;

    report.eyebrow('Lote', {gapAfter: 4});
    report.heading(`${lote.lote_nombre || 'Sin nombre'} (${lote.cultivo_nombre || 'Cultivo sin identificar'})`, {size: 13, gapBefore: 3, gapAfter: 8});
    if (lote.observacion) report.paragraph(`Observación del lote: ${lote.observacion}`, {size: 9, color: REPORT_COLORS.muted});
    if (sinNovedades) {
      report.paragraph('Sin novedades registradas para este lote.', {size: 9, color: REPORT_COLORS.muted, italic: true});
    }

    if (lotHallazgos.length) {
      report.sectionHeading('01', 'Hallazgo');
      lotHallazgos.forEach((h) => report.paragraph(h.descripcion || '', {size: 10}));
      // Solo se incrusta la primera foto disponible, igual que el reporte móvil.
      const conFoto = lotHallazgos.find((h) => h.imagen_path_srv);
      if (conFoto) {
        const dataUrl = await reportFetchDataUrl(conFoto.imagen_path_srv).catch(() => null);
        await report.image(dataUrl);
      }
    }

    if (lotActividades.length) {
      report.sectionHeading('02', 'Actividades');
      report.bullets(lotActividades.map((a) => a.labor_nombre || 'Labor sin nombre'));
    }

    if (lotFormulas.length) {
      report.sectionHeading('03', 'Fórmulas a aplicar');
      const titulo = lotFormulaHeaders[0]?.und_global
        ? `Fórmula para ${lotFormulaHeaders[0].und_global}`
        : 'Fórmula';
      report.paragraph(titulo, {size: 10, bold: true, gapAfter: 4});

      const grupos = {};
      lotFormulas.forEach((item) => {
        (grupos[item.group_id] ||= []).push(item);
      });
      const rows = Object.values(grupos).map((grupo) => [
        grupo.map((i) => i.insumo_nombre || '').join('\n'),
        grupo.map((i) => i.dosis || '-').join('\n'),
        grupo.map((i) => i.unidad || '').join('\n'),
        grupo.map((i) => i.obs_insumo || '').join('\n'),
      ]);
      doc.autoTable({
        startY: report.y,
        margin: {left: REPORT_MARGIN, right: REPORT_MARGIN},
        head: [['Insumo', 'Dosis', 'Unidad', 'Observación']],
        body: rows,
        theme: 'grid',
        styles: {font: 'helvetica', fontSize: 9, textColor: REPORT_COLORS.ink, lineColor: REPORT_COLORS.border},
        headStyles: {fillColor: REPORT_COLORS.forest, textColor: REPORT_COLORS.cream, fontStyle: 'bold'},
        alternateRowStyles: {fillColor: REPORT_COLORS.cream},
      });
      report.y = doc.lastAutoTable.finalY + 16;

      const obsGlobal = lotFormulaHeaders[0]?.obs_global;
      if (obsGlobal) {
        report.paragraph('Observaciones:', {size: 10, bold: true, gapAfter: 2});
        report.paragraph(obsGlobal, {size: 10, gapAfter: 6});
      }
    }

    if (lotRecomendaciones.length) {
      report.sectionHeading('04', 'Recomendaciones');
      report.bullets(lotRecomendaciones.map((r) => r.texto || r.recomendacion_base || ''));
    }

    report.divider();
  }

  // ===================== Firma =====================
  report.ensureSpace(180);
  report.y += 40;
  report.paragraph('Atentamente,', {size: 11, gapAfter: 24});
  if (firma?.firma_base64) {
    await report.image(`data:image/png;base64,${firma.firma_base64}`, {maxHeight: 70, center: false});
    report.y += 4;
  }
  if (firma?.nombre) {
    report.ensureSpace(14);
    doc.setDrawColor(REPORT_COLORS.ink);
    doc.line(REPORT_MARGIN, report.y, REPORT_MARGIN + 190, report.y);
    report.y += 14;
    report.paragraph(firma.nombre, {size: 12, bold: true, gapAfter: 2});
    if (firma.titulo) report.paragraph(firma.titulo, {size: 10, color: REPORT_COLORS.muted, italic: true, gapAfter: 2});
    const contacto = [
      firma.tarjeta_profesional ? `TP ${firma.tarjeta_profesional}` : null,
      firma.celular ? `Cel. ${firma.celular}` : null,
    ].filter(Boolean).join('  ·  ');
    if (contacto) report.paragraph(contacto, {size: 9.5, color: REPORT_COLORS.muted});
  } else {
    report.paragraph('Este técnico aún no ha configurado su firma y datos de perfil.', {
      size: 9, color: REPORT_COLORS.muted, italic: true,
    });
  }

  // ===================== Marca de página + pie =====================
  const totalPages = doc.internal.getNumberOfPages();
  for (let page = 1; page <= totalPages; page += 1) {
    doc.setPage(page);
    doc.setFillColor(REPORT_COLORS.forest);
    doc.rect(0, 0, REPORT_PAGE_W, 6, 'F');
    doc.rect(0, 0, 6, REPORT_PAGE_H, 'F');
    doc.setDrawColor(REPORT_COLORS.border);
    doc.line(REPORT_MARGIN, REPORT_FOOTER_TOP, REPORT_PAGE_W - REPORT_MARGIN, REPORT_FOOTER_TOP);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(REPORT_COLORS.muted);
    doc.text(`© ${year} AgroSoft Agrónomo · Generado desde el panel web`, REPORT_MARGIN, REPORT_FOOTER_TOP + 12);
    doc.text(`Página ${page} de ${totalPages}`, REPORT_MARGIN, REPORT_FOOTER_TOP + 22);
    doc.text('www.agronomo.agrosoft.com.co', REPORT_MARGIN, REPORT_FOOTER_TOP + 32);
  }

  const nombreArchivo = `visita-${visita.fecha}-${(visita.finca_nombre || 'finca').replace(/\s+/g, '_')}.pdf`;
  doc.save(nombreArchivo);
}

async function generateVisitPdf() {
  if (!visitState.detail || !visitState.selected) return;
  Swal.fire({
    title: 'Construyendo PDF...',
    text: 'Un momento por favor.',
    allowOutsideClick: false,
    allowEscapeKey: false,
    didOpen: () => Swal.showLoading(),
  });
  try {
    await buildVisitReportPdf();
    Swal.close();
  } catch (error) {
    Swal.fire({icon: 'error', title: 'No fue posible generar el PDF', text: error.message, confirmButtonColor: '#173f32'});
  }
}
