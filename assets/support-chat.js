(function () {
  const widget = document.getElementById("vilahub-ai-widget"),
    launcher = document.getElementById("vilahub-ai-launcher"),
    dismiss = document.getElementById("vilahub-ai-dismiss"),
    panel = document.getElementById("vilahub-ai-panel"),
    close = document.getElementById("vilahub-ai-close"),
    messages = document.getElementById("vilahub-support-messages"),
    form = document.getElementById("vilahub-support-form"),
    input = document.getElementById("vilahub-support-input"),
    transfer = document.getElementById("vilahub-support-transfer"),
    status = document.getElementById("vilahub-support-status");
  if (!widget || !launcher || !panel || !messages || !form) return;
  let loaded = false,
    dragged = false;
  const post = async (operation, extra = {}) => {
    const body = new FormData();
    body.append("action", "vilahub_support_chat");
    body.append("nonce", vilahubSupportChat.nonce);
    body.append("operation", operation);
    body.append("page", vilahubSupportChat.page);
    Object.entries(extra).forEach(([key, value]) => body.append(key, value));
    const response = await fetch(vilahubSupportChat.url, {
      method: "POST",
      credentials: "same-origin",
      body,
    });
    const data = await response.json();
    if (!data.success)
      throw new Error(data.data?.message || "Não foi possível concluir.");
    return data.data;
  };
  const linkedText = (text) => {
    const value = String(text),
      fragment = document.createDocumentFragment(),
      pattern = /https?:\/\/[^\s<>"']+/gi;
    let cursor = 0,
      match;
    while ((match = pattern.exec(value)) !== null) {
      if (match.index > cursor)
        fragment.append(
          document.createTextNode(value.slice(cursor, match.index)),
        );
      let url = match[0],
        suffix = "";
      while (/[.,;:!?\)\]\}]$/.test(url)) {
        suffix = url.slice(-1) + suffix;
        url = url.slice(0, -1);
      }
      if (url) {
        const link = document.createElement("a");
        link.href = url;
        link.textContent = url;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.style.overflowWrap = "anywhere";
        fragment.append(link);
      }
      if (suffix) fragment.append(document.createTextNode(suffix));
      cursor = match.index + match[0].length;
    }
    if (cursor < value.length)
      fragment.append(document.createTextNode(value.slice(cursor)));
    return fragment;
  };
  const add = (role, text) => {
    const article = document.createElement("article");
    article.className =
      "vh-support-message " + (role === "USER" ? "is-user" : "is-assistant");
    const label = document.createElement("strong");
    label.textContent = role === "USER" ? "Você" : "Assistente VilaHub";
    const content = document.createElement("p");
    content.append(linkedText(text));
    article.append(label, content);
    messages.append(article);
    messages.scrollTop = messages.scrollHeight;
  };
  const load = async () => {
    if (loaded) return;
    loaded = true;
    messages.innerHTML =
      '<p class="vh-support-loading">Abrindo atendimento…</p>';
    try {
      const conversation = await post("start");
      messages.innerHTML = "";
      (conversation.messages || []).forEach((item) =>
        add(item.role, item.content),
      );
      if (conversation.status === "TRANSFERRED") {
        status.textContent = "Conversa encaminhada ao suporte humano.";
        form.hidden = true;
        transfer.hidden = true;
      }
    } catch (error) {
      messages.innerHTML = "";
      add("ASSISTANT", error.message);
    }
  };
  const placePanel = () => {
    const rect = launcher.getBoundingClientRect(),
      width = Math.min(390, window.innerWidth - 24),
      height = Math.min(610, window.innerHeight - 90);
    let left = Math.max(
        12,
        Math.min(window.innerWidth - width - 12, rect.right - width),
      ),
      top = rect.top - height - 10;
    if (top < 12)
      top = Math.min(window.innerHeight - height - 12, rect.bottom + 10);
    panel.style.left = left + "px";
    panel.style.top = Math.max(12, top) + "px";
  };
  const showLauncher = (visible) => {
    const controls = widget.querySelector(".vh-ai-controls");
    if (!controls) return;
    controls.style.visibility = visible ? "visible" : "hidden";
    controls.style.pointerEvents = visible ? "" : "none";
  };
  const open = () => {
    panel.hidden = false;
    launcher.setAttribute("aria-expanded", "true");
    placePanel();
    showLauncher(false);
    void load();
    window.setTimeout(() => input?.focus(), 50);
  };
  const shut = () => {
    panel.hidden = true;
    launcher.setAttribute("aria-expanded", "false");
    showLauncher(true);
  };
  launcher.addEventListener("click", () => {
    if (dragged) {
      dragged = false;
      return;
    }
    panel.hidden ? open() : shut();
  });
  close?.addEventListener("click", shut);
  dismiss?.addEventListener("click", () => {
    shut();
    widget.hidden = true;
    sessionStorage.setItem("vilahub-ai-hidden", "1");
  });
  try {
    const saved = JSON.parse(
      localStorage.getItem("vilahub-ai-position") || "null",
    );
    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
      widget.style.left =
        Math.max(
          0,
          Math.min(window.innerWidth - widget.offsetWidth, saved.left),
        ) + "px";
      widget.style.top =
        Math.max(
          32,
          Math.min(window.innerHeight - widget.offsetHeight, saved.top),
        ) + "px";
      widget.style.right = "auto";
      widget.style.bottom = "auto";
    }
  } catch (_) {}
  if (sessionStorage.getItem("vilahub-ai-hidden") === "1") widget.hidden = true;
  launcher.addEventListener("pointerdown", (event) => {
    if (event.button !== 0) return;
    const startX = event.clientX,
      startY = event.clientY,
      rect = widget.getBoundingClientRect();
    let moving = false;
    launcher.setPointerCapture(event.pointerId);
    const move = (current) => {
      const dx = current.clientX - startX,
        dy = current.clientY - startY;
      if (!moving && Math.hypot(dx, dy) < 5) return;
      moving = true;
      dragged = true;
      shut();
      widget.style.left =
        Math.max(
          0,
          Math.min(window.innerWidth - widget.offsetWidth, rect.left + dx),
        ) + "px";
      widget.style.top =
        Math.max(
          32,
          Math.min(window.innerHeight - widget.offsetHeight, rect.top + dy),
        ) + "px";
      widget.style.right = "auto";
      widget.style.bottom = "auto";
    };
    const end = () => {
      launcher.removeEventListener("pointermove", move);
      launcher.removeEventListener("pointerup", end);
      launcher.removeEventListener("pointercancel", end);
      if (moving)
        localStorage.setItem(
          "vilahub-ai-position",
          JSON.stringify({
            left: parseFloat(widget.style.left),
            top: parseFloat(widget.style.top),
          }),
        );
    };
    launcher.addEventListener("pointermove", move);
    launcher.addEventListener("pointerup", end);
    launcher.addEventListener("pointercancel", end);
  });
  window.addEventListener("resize", () => {
    if (!panel.hidden) placePanel();
  });
  if (vilahubSupportChat.open && !widget.hidden) open();
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    add("USER", text);
    input.value = "";
    input.disabled = true;
    status.textContent = "Pensando…";
    try {
      const answer = await post("message", { message: text });
      add("ASSISTANT", answer.answer);
      status.textContent = answer.transferRecommended
        ? "Se precisar, fale com uma pessoa."
        : "";
    } catch (error) {
      add("ASSISTANT", error.message);
      status.textContent = "";
    } finally {
      input.disabled = false;
      input.focus();
    }
  });
  transfer.addEventListener("click", async () => {
    transfer.disabled = true;
    status.textContent = "Abrindo chamado…";
    try {
      const result = await post("transfer");
      status.textContent =
        "Chamado aberto: " + (result.protocol || "encaminhado") + ".";
      form.hidden = true;
      transfer.hidden = true;
    } catch (error) {
      status.textContent = error.message;
      transfer.disabled = false;
    }
  });
})();
