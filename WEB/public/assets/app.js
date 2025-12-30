(() => {
  const digits = (value) => String(value || "").replace(/\D+/g, "");

  const formatPhone = (value) => {
    const d = digits(value);
    if (d.length === 0) return "";

    const ddd = d.slice(0, 2);
    if (d.length <= 2) return `(${ddd}`;

    const isMobile = d.length >= 11;
    const first = isMobile ? d.slice(2, 7) : d.slice(2, 6);
    const last = isMobile ? d.slice(7, 11) : d.slice(6, 10);

    if (d.length <= (isMobile ? 7 : 6)) return `(${ddd}) ${first}`;
    return `(${ddd}) ${first}-${last}`;
  };

  const parseMoney = (value) => {
    const raw = String(value || "").replace(/[^\d.,]/g, "").trim();
    if (!raw) return null;

    const lastComma = raw.lastIndexOf(",");
    const lastDot = raw.lastIndexOf(".");

    let decimalSep = null;
    if (lastComma !== -1 && lastDot !== -1) decimalSep = lastComma > lastDot ? "," : ".";
    else if (lastComma !== -1) decimalSep = ",";
    else if (lastDot !== -1) decimalSep = ".";

    let whole = raw;
    let frac = "00";
    if (decimalSep) {
      const pos = raw.lastIndexOf(decimalSep);
      whole = raw.slice(0, pos);
      frac = raw.slice(pos + 1);
    }

    whole = whole.replace(/\D+/g, "") || "0";
    frac = (frac.replace(/\D+/g, "") + "00").slice(0, 2);

    return `${whole}.${frac}`;
  };

  const formatMoney = (normalized) => {
    if (!normalized) return "";
    const [wholeRaw, fracRaw] = String(normalized).split(".");
    const whole = (wholeRaw || "0").replace(/\D+/g, "") || "0";
    const frac = (fracRaw || "00").replace(/\D+/g, "").padEnd(2, "0").slice(0, 2);
    const wholeFormatted = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return `R$ ${wholeFormatted},${frac}`;
  };

  const attachPhoneMask = (input) => {
    const apply = () => {
      const next = formatPhone(input.value);
      if (next !== input.value) input.value = next;
    };
    input.addEventListener("input", apply);
    input.addEventListener("blur", apply);
    apply();
  };

  const attachMoneyMask = (input) => {
    const sanitize = () => {
      input.value = String(input.value || "").replace(/[^\d.,]/g, "");
    };

    const apply = () => {
      const normalized = parseMoney(input.value);
      input.value = normalized ? formatMoney(normalized) : "";
    };

    input.addEventListener("focus", () => {
      let v = String(input.value || "");
      v = v.replace(/^R\$\s*/i, "");
      v = v.replace(/\./g, "");
      input.value = v;
      requestAnimationFrame(() => {
        try {
          input.select();
        } catch {}
      });
    });
    input.addEventListener("input", sanitize);
    input.addEventListener("blur", apply);
    apply();
  };

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('input[data-mask="phone"]').forEach(attachPhoneMask);
    document.querySelectorAll('input[data-mask="money"]').forEach(attachMoneyMask);
  });
})();

