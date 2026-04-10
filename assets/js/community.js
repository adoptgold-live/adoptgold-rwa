async function loadFeed() {
  try {
    const res = await fetch('/rwa/api/community/feed.php');
    const data = await res.json();

    const el = document.getElementById('feedList');

    if (!data.ok) {
      el.innerHTML = 'Failed to load';
      return;
    }

    if (!data.rows.length) {
      el.innerHTML = 'No activity yet';
      return;
    }

    el.innerHTML = data.rows.map(r => `
      <div class="feed-item">
        <div><b>${r.title}</b></div>
        <div>${r.body}</div>
      </div>
    `).join('');

  } catch (e) {
    document.getElementById('feedList').innerHTML = 'Error';
  }
}

loadFeed();
