<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>AI Chat</title>
  <style>
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,sans-serif;display:flex;height:100vh;background:#f5f5f5}
    aside{width:260px;background:#1f2937;color:#fff;padding:12px;overflow:auto}
    aside a{display:block;color:#d1d5db;padding:8px;border-radius:6px;text-decoration:none;font-size:14px}
    aside a.active,aside a:hover{background:#374151}
    aside button{width:100%;padding:10px;margin-bottom:10px;cursor:pointer}
    main{flex:1;display:flex;flex-direction:column}
    #messages{flex:1;overflow:auto;padding:20px}
    .msg{max-width:75%;padding:10px 14px;margin:8px 0;border-radius:12px;white-space:pre-wrap;line-height:1.5}
    .user{background:#2563eb;color:#fff;margin-left:auto}
    .assistant{background:#fff}
    form{display:flex;gap:8px;padding:12px;background:#fff;border-top:1px solid #ddd}
    textarea{flex:1;padding:10px;resize:none;font:inherit}
    form button{padding:0 20px;cursor:pointer}
  </style>
</head>
<body>
  <aside>
    <form method="POST" action="{{ route('chat.create') }}">@csrf<button>+ New chat</button></form>
    @foreach($conversations as $c)
      <a href="{{ route('chat.show', $c) }}" class="{{ $c->id === $conversation->id ? 'active' : '' }}">{{ $c->title }}</a>
    @endforeach
  </aside>

  <main>
    <div id="messages">
      @foreach($messages as $m)
        <div class="msg {{ $m->role }}">{{ $m->content }}</div>
      @endforeach
    </div>
    <form id="chat-form">
      <textarea id="input" rows="2" placeholder="Type your message..." required></textarea>
      <button id="send">Send</button>
    </form>
  </main>

<script>
const box = document.getElementById('messages');
const form = document.getElementById('chat-form');
const input = document.getElementById('input');
const sendBtn = document.getElementById('send');
const csrf = document.querySelector('meta[name=csrf-token]').content;
const url = "{{ route('chat.stream', $conversation) }}";

function addMsg(role, text = '') {
  const d = document.createElement('div');
  d.className = 'msg ' + role;
  d.textContent = text;
  box.appendChild(d);
  box.scrollTop = box.scrollHeight;
  return d;
}
box.scrollTop = box.scrollHeight;

input.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
});

form.addEventListener('submit', async e => {
  e.preventDefault();
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  sendBtn.disabled = true;
  addMsg('user', text);
  const bot = addMsg('assistant', '...');
  let first = true;

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'text/event-stream'},
      body: JSON.stringify({message: text})
    });
    const reader = res.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    while (true) {
      const {done, value} = await reader.read();
      if (done) break;
      buf += dec.decode(value, {stream: true});
      const parts = buf.split('\n\n');
      buf = parts.pop();
      for (const p of parts) {
        const data = p.replace(/^data: /, '');
        if (data === '[DONE]') continue;
        if (first) { bot.textContent = ''; first = false; }
        bot.textContent += JSON.parse(data).t;
        box.scrollTop = box.scrollHeight;
      }
    }
  } catch (err) {
    bot.textContent = 'Error: ' + err.message;
  }
  sendBtn.disabled = false;
  input.focus();
});
</script>
</body>
</html>
