<template>
  <main class="auth-shell">
    <section class="auth-aside">
      <div class="brand">
        <img class="brand-mark" src="/hris-icon.png" alt="" width="48" height="48" />
        <span><b>HRIS</b><small>People & workplace</small></span>
      </div>
      <div class="auth-aside-copy">
        <span class="eyebrow">YOUR WORKDAY, CONNECTED</span>
        <h1>People first.<br />Everything in place.</h1>
        <p>A simpler way to manage your employee information, time, requests, and records.</p>
        <div class="auth-features">
          <span><i class="pi pi-calendar-clock" /> Time & attendance</span
          ><span><i class="pi pi-file-check" /> Requests & approvals</span
          ><span><i class="pi pi-id-card" /> Employee self-service</span>
        </div>
      </div>
      <small class="auth-footer"><i class="pi pi-shield" /> Secure employee workspace</small>
      <div class="auth-decoration" aria-hidden="true"><span /><span /><span /></div>
    </section>
    <section class="auth-panel"><slot /></section>
  </main>
</template>
