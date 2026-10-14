// ===== Sidebar toggle (mobile) =====
document.addEventListener('DOMContentLoaded', function () {
  const toggleBtn = document.getElementById('sidebarToggle');
  const sidebar = document.querySelector('.sidebar');
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      sidebar.classList.toggle('show');
    });
    document.addEventListener('click', function (e) {
      if (window.innerWidth <= 991 && sidebar.classList.contains('show') &&
          !sidebar.contains(e.target) && e.target !== toggleBtn) {
        sidebar.classList.remove('show');
      }
    });
  }

  // ===== Image preview before upload =====
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
    input.addEventListener('change', function () {
      const previewEl = document.getElementById(this.dataset.preview);
      if (this.files && this.files[0] && previewEl) {
        const reader = new FileReader();
        reader.onload = function (ev) {
          previewEl.src = ev.target.result;
          previewEl.classList.remove('d-none');
        };
        reader.readAsDataURL(this.files[0]);
      }
    });
  });

  // ===== AI advice form (AJAX) =====
  const aiForm = document.getElementById('aiAdviceForm');
  const histBox = document.getElementById('adviceHistory');
  const plotSelect = document.getElementById('aiPlotSelect');

  // โหลดประวัติคำแนะนำของแปลง (HTML จากเซิร์ฟเวอร์) ใช้ skip เพื่อไม่ให้ซ้ำกับผลที่เพิ่งได้
  async function loadAdviceHistory(plotId, skipId) {
    if (!histBox || !plotId) return;
    histBox.innerHTML = '<p class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลดประวัติ...</p>';
    try {
      const resp = await fetch('ai_advice_history.php?plot_id=' + encodeURIComponent(plotId) + (skipId ? '&skip=' + skipId : ''));
      histBox.innerHTML = await resp.text();
    } catch (err) {
      histBox.innerHTML = '<p class="text-danger">โหลดประวัติไม่สำเร็จ: ' + escapeHtml(err.message) + '</p>';
    }
  }
  if (plotSelect) {
    plotSelect.addEventListener('change', function () { loadAdviceHistory(this.value, 0); });
  }

  if (aiForm) {
    aiForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const resultBox = document.getElementById('aiResultBox');
      const submitBtn = document.getElementById('aiSubmitBtn');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังวิเคราะห์...';
      resultBox.innerHTML = '<div class="ai-loading"><span class="spinner-border spinner-border-sm"></span> ระบบกำลังคำนวณคะแนนพืชและให้ AI เขียนคำอธิบาย อาจใช้เวลาสักครู่ (ครั้งแรกหลังเปิดเครื่องจะนานกว่า)...</div>';

      try {
        const formData = new FormData(aiForm);
        const resp = await fetch(aiForm.action, { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
          resultBox.innerHTML = data.html;
          loadAdviceHistory(formData.get('plot_id'), data.recommend_id);
        } else {
          resultBox.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(data.message || 'เกิดข้อผิดพลาด') + '</div>';
        }
      } catch (err) {
        resultBox.innerHTML = '<div class="alert alert-danger mb-0">ไม่สามารถเชื่อมต่อระบบได้: ' + escapeHtml(err.message) + '</div>';
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-stars me-1"></i> ขอคำแนะนำ';
      }
    });
  }

  // ฟอร์มที่เรียก AI ใช้เวลานาน (class js-wait): ปิดปุ่ม + แสดงข้อความรอ กันกดซ้ำ (ทำงานกับฟอร์มที่แทรกด้วย JS ด้วย)
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!f.classList || !f.classList.contains('js-wait')) return;
    const btn = f.querySelector('button:not([type]), button[type="submit"]');
    if (btn) {
      setTimeout(function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (f.dataset.waitText || 'กำลังดำเนินการ...');
      }, 0);
    }
  });

  function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }
});
