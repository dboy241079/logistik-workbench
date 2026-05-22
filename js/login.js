document.addEventListener('DOMContentLoaded', () => {
  // === LOGIN-TOGGLE: "Hier einloggen" blendet die Login-Card ein ===========
  const toggleBtn = document.getElementById('loginToggleBtn');
  const landing   = document.getElementById('landingContent');
  const loginBox  = document.getElementById('loginBox');

  if (toggleBtn && landing && loginBox) {
    function openLogin() {
      // Hero ausblenden
      landing.classList.add('d-none');
      // Login-Card sichtbar + Animation
      loginBox.classList.remove('d-none');
      loginBox.classList.add('show');

      const userInput = loginBox.querySelector('input[name="username"]');
      if (userInput) userInput.focus();
    }

    toggleBtn.addEventListener('click', (e) => {
      e.preventDefault();
      openLogin();
    });

    // Wenn Login-Fehler vorliegt, ist die Box per PHP schon "show"
    if (loginBox.classList.contains('show')) {
      const userInput = loginBox.querySelector('input[name="username"]');
      if (userInput) userInput.focus();
    }
  }

  // === TYPEWRITER: Zwei Texte im Loop (vorwärts schreiben, rückwärts löschen)
  const typeTarget = document.getElementById('typewriterText');
  if (typeTarget) {
    const texts = [
      typeTarget.dataset.textMain || 'Willkommen in deiner Logistik-Workbench',
      typeTarget.dataset.textAlt  || 'Willkommen hier in Wunstorf / Hannover'
    ];

    let textIndex = 0;   // aktueller Text
    let charIndex = 0;   // aktuelle Zeichenposition
    let deleting  = false;

    const typeSpeed   = 70;   // Schreiben
    const deleteSpeed = 40;   // Löschen
    const pauseFull   = 1200; // Pause, wenn Text komplett ist
    const pauseEmpty  = 400;  // Pause, wenn alles gelöscht ist

    function tick() {
      const current = texts[textIndex];

      if (!deleting) {
        // Schreiben
        charIndex++;
        typeTarget.textContent = current.slice(0, charIndex);

        if (charIndex === current.length) {
          // kompletter Text -> kurze Pause, dann löschen
          deleting = true;
          setTimeout(tick, pauseFull);
          return;
        }

        setTimeout(tick, typeSpeed);
      } else {
        // Löschen
        charIndex--;
        typeTarget.textContent = current.slice(0, charIndex);

        if (charIndex === 0) {
          // alles gelöscht -> nächster Text
          deleting  = false;
          textIndex = (textIndex + 1) % texts.length;
          setTimeout(tick, pauseEmpty);
          return;
        }

        setTimeout(tick, deleteSpeed);
      }
    }

    typeTarget.textContent = '';
    tick();
  }

  // === UX: Shake bei Login-Fehler + Enter-„Klick“ im Passwortfeld ==========
  const loginCard = document.querySelector('.login-card');
  if (loginCard) {
    const pwdInput = loginCard.querySelector('input[name="password"]');
    const submitBtn = loginCard.querySelector('button[type="submit"]');

    // Wenn eine Fehlermeldung vorhanden ist -> Shake-Animation
    if (loginCard.querySelector('.alert-danger')) {
      loginCard.classList.add('shake');
      setTimeout(() => loginCard.classList.remove('shake'), 600);
    }

    // Enter im Passwortfeld lässt den Button kurz "klicken"
    if (pwdInput && submitBtn) {
      pwdInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          submitBtn.classList.add('active');
          setTimeout(() => submitBtn.classList.remove('active'), 150);
        }
      });
    }
  }
});
