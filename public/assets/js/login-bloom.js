/* Zellie Fleurs — "Cosmic Bloom" ambience behind the admin login page.
   Decorative only: canvas is pointer-events:none, all listeners sit at
   document/window level and never preventDefault or stopPropagation. */
(() => {
    'use strict';

    const canvas = document.querySelector('.login-bloom');
    if (!canvas || !canvas.getContext) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Champagne gold base; slow drift eases it toward soft blush and back.
    const HUE_BASE = 44;
    const HUE_RANGE = 12;
    const HUE_SPEED = 0.33;
    // Rebuild cached gradients only once hue has drifted this far.
    const HUE_REBUILD_STEP = 3;

    const STAR_CAP = 140;
    const SMALL_VIEW = 600; // px; below this use the mobile mote cap / power

    let w = 0;
    let h = 0;
    let time = 0;
    let hue = HUE_BASE;
    let builtHue = -999;
    let maxMotes = 220;
    let powerScale = 1;
    // Focal point of the bloom — kept clear of the centered login panel
    // (recomputed in resize(), so it stays responsive to the viewport).
    let fx = 0;
    let fy = 0;
    let focusScale = 1;
    let running = false;
    let rafId = 0;
    let resizeTimer = 0;

    let glowGrad = null;
    let coreGrad = null;
    let baseGrad = null; // full-screen trail fill: plum base + baked-in tints

    const pointer = { x: -1000, y: -1000, active: false };
    const stars = [];
    const motes = [];
    const ripples = [];

    const rand = (a, b) => a + Math.random() * (b - a);
    const reducedMotion = () => {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (error) {
            return false; // matchMedia unavailable → assume motion is fine
        }
    };

    // RESIZE / WORLD REBUILD
    function resize() {
        // dpr capped at 1: the trail-fade fillRect runs full-screen every
        // frame, so 2x/3x phone screens would quadruple the pixel work.
        w = window.innerWidth;
        h = window.innerHeight;

        canvas.width = w;
        canvas.height = h;
        ctx.setTransform(1, 0, 0, 1, 0, 0);

        const small = w < SMALL_VIEW;
        maxMotes = small ? 110 : 220;
        powerScale = small ? 0.7 : 1;

        // Responsive focal point: the bloom stays deliberately docked in the
        // UPPER-RIGHT on every viewport, clear of the centered 430px panel.
        // Wide screens park it beside the panel's right edge at 28% height;
        // narrow/portrait screens tuck a scaled-down bloom into the top
        // margin above the panel (never lower than 56px, and scaled down
        // further on short landscape phones). Recomputed on every resize,
        // so rotation and window changes re-dock it correctly.
        if (w >= 900) {
            fx = w * 0.8;
            fy = h * 0.28;
            focusScale = w >= 1100 ? 0.95 : 0.8;
        } else {
            fx = w * 0.8;
            fy = Math.max(h * 0.12, 56);
            focusScale = h < 500 ? 0.5 : 0.6;
        }

        stars.length = 0;
        const count = Math.min(STAR_CAP, Math.floor(w * h / 3200));
        for (let i = 0; i < count; i++) {
            stars.push({
                x: Math.random() * w,
                y: Math.random() * h,
                r: rand(0.35, 1.35),
                a: rand(0.15, 0.8),
                p: rand(0, 6.28),
                s: rand(0.3, 1.3)
            });
        }

        builtHue = -999; // force gradient rebuild
        buildGradients();

        // opaque base so the first paint already matches the page backdrop
        ctx.globalAlpha = 1;
        ctx.fillStyle = baseGrad;
        ctx.fillRect(0, 0, w, h);
    }

    // Gradients + the tinted base layer — built rarely, never per frame.
    function buildGradients() {
        if (Math.abs(hue - builtHue) < HUE_REBUILD_STEP) return;
        builtHue = hue;

        // Soften the glow on narrow viewports (phone-sized screens).
        const glowInner = w < 900 ? 0.10 : 0.14;
        const glowMid = w < 900 ? 0.045 : 0.06;

        glowGrad = ctx.createRadialGradient(0, 0, 2, 0, 0, Math.min(w, h) * 0.34 * focusScale);
        glowGrad.addColorStop(0, `hsla(${hue}, 70%, 68%, ${glowInner})`);
        glowGrad.addColorStop(0.35, `hsla(${hue + 28}, 62%, 58%, ${glowMid})`);
        glowGrad.addColorStop(1, 'rgba(36, 26, 32, 0)');

        coreGrad = ctx.createRadialGradient(0, 0, 0, 0, 0, 34);
        coreGrad.addColorStop(0, 'rgba(255, 253, 248, 0.95)');
        coreGrad.addColorStop(0.12, `hsla(${hue + 25}, 72%, 84%, 0.9)`);
        coreGrad.addColorStop(0.42, `hsla(${hue}, 68%, 64%, 0.34)`);
        coreGrad.addColorStop(1, 'rgba(36, 26, 32, 0)');

        baseGrad = ctx.createRadialGradient(w * 0.2, h * 0.15, 0, w * 0.5, h * 0.5, Math.max(w, h) * 0.9);
        baseGrad.addColorStop(0, '#2b1f26');
        baseGrad.addColorStop(0.45, '#241a20');
        baseGrad.addColorStop(1, '#1e151b');
    }

    // PARTICLE BURST — motes lean gold, with blush/green accents.
    function makeBloom(x, y, power) {
        const room = maxMotes - motes.length;
        const count = Math.max(0, Math.min(Math.floor(60 * power * powerScale), room));

        for (let i = 0; i < count; i++) {
            const angle = rand(0, Math.PI * 2);
            const speed = rand(0.5, 4.8) * power;
            const roll = Math.random();

            // ~20% blush, ~15% botanical green, rest champagne family
            let particleHue = hue + rand(-20, 20);
            if (roll < 0.2) particleHue = 350 + rand(-5, 5);
            else if (roll < 0.35) particleHue = 104 + rand(-6, 6);

            motes.push({
                x,
                y,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed,
                life: rand(55, 125),
                max: 125,
                size: rand(0.7, 2.6),
                hue: particleHue,
                sat: rand(58, 78),
                light: rand(70, 85),
                twist: rand(-0.035, 0.035)
            });
        }

        ripples.push({ x, y, r: 4, life: 1 });
    }

    // CENTRAL COSMIC FLOWER — layered petal curves around a glowing core.
    function drawCore(cx, cy, t) {
        const pulse = 1 + Math.sin(t * 1.3) * 0.035;

        ctx.save();

        // glow via cached gradient drawn around the origin, offset to center
        ctx.fillStyle = glowGrad;
        ctx.save();
        ctx.translate(cx, cy);
        ctx.fillRect(-cx, -cy, w, h);
        ctx.restore();
        ctx.translate(cx, cy);

        ctx.scale(pulse * focusScale, pulse * focusScale);

        // no shadowBlur — the layered fading strokes read as glow at a
        // fraction of the mobile GPU cost
        const STEPS = 180;
        for (let layer = 0; layer < 5; layer++) {
            const petals = 9 + layer * 2;

            ctx.beginPath();
            for (let i = 0; i <= STEPS; i++) {
                const a = (i / STEPS) * Math.PI * 2;
                const wave = Math.sin(petals * a + t * (0.22 + layer * 0.018) + layer) * (0.22 + layer * 0.012);
                const r = 12 + layer * 9 + Math.sin(a * 3 + t * 0.5) * 7 + wave * 72;
                const x = Math.cos(a + layer * 0.12) * r;
                const y = Math.sin(a + layer * 0.12) * r * 0.72;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.closePath();

            ctx.strokeStyle = `hsla(${hue + layer * 8}, 62%, ${74 + layer * 2}%, ${0.26 - layer * 0.02})`;
            ctx.lineWidth = 1.3;
            ctx.stroke();
        }

        ctx.fillStyle = coreGrad;
        ctx.beginPath();
        ctx.arc(0, 0, 34, 0, Math.PI * 2);
        ctx.fill();

        ctx.restore();
    }

    // MAIN LOOP
    function frame() {
        time += 0.008;

        // slow automatic drift: champagne gold ↔ soft blush
        hue = HUE_BASE + Math.sin(time * HUE_SPEED) * HUE_RANGE;
        buildGradients(); // no-op unless hue moved ≥ HUE_REBUILD_STEP

        // trail fade toward the tinted plum base
        ctx.globalAlpha = 0.26;
        ctx.fillStyle = baseGrad;
        ctx.fillRect(0, 0, w, h);
        ctx.globalAlpha = 1;

        // TWINKLING STARS — warm ivory instead of cyber blue
        for (const s of stars) {
            const twinkle = 0.5 + 0.5 * Math.sin(time * 2 * s.s + s.p);
            ctx.fillStyle = `rgba(246, 231, 209, ${s.a * (0.35 + twinkle * 0.65)})`;
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
            ctx.fill();
        }

        const cx = fx;
        const cy = fy;

        drawCore(cx, cy, time);

        // 4 WOBBLING ORBITAL RINGS
        const RING_STEPS = 110;
        for (let ring = 0; ring < 4; ring++) {
            ctx.beginPath();
            const radius = Math.min(w, h) * (0.19 + ring * 0.075) * focusScale;
            for (let i = 0; i <= RING_STEPS; i++) {
                const a = (i / RING_STEPS) * Math.PI * 2 + time * (ring % 2 ? -0.11 : 0.09);
                const wobble = Math.sin(a * (5 + ring) + time * 1.4) * 8;
                const x = cx + Math.cos(a) * (radius + wobble);
                const y = cy + Math.sin(a) * (radius + wobble) * 0.62;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.strokeStyle = `hsla(${hue + ring * 14}, 58%, 78%, ${0.1 - ring * 0.012})`;
            ctx.lineWidth = 0.8;
            ctx.stroke();
        }

        // POINTER-FOLLOW LIGHT
        if (pointer.active) {
            const grad = ctx.createRadialGradient(pointer.x, pointer.y, 0, pointer.x, pointer.y, 150);
            grad.addColorStop(0, `hsla(${hue + 30}, 68%, 74%, 0.08)`);
            grad.addColorStop(1, 'rgba(36, 26, 32, 0)');
            ctx.fillStyle = grad;
            ctx.fillRect(pointer.x - 150, pointer.y - 150, 300, 300);
        }

        // MOTES — twist/orbital drift, no shadowBlur
        for (let i = motes.length - 1; i >= 0; i--) {
            const p = motes[i];
            const dx = pointer.x - p.x;
            const dy = pointer.y - p.y;
            const dist = Math.hypot(dx, dy);

            if (pointer.active && dist < 130) {
                p.vx -= (dx / (dist + 1)) * 0.025;
                p.vy -= (dy / (dist + 1)) * 0.025;
            }

            p.vx += -p.y * 0.000012 + p.twist * -p.vy;
            p.vy += p.x * 0.000012 + p.twist * p.vx;

            p.x += p.vx;
            p.y += p.vy;
            p.vx *= 0.994;
            p.vy *= 0.994;
            p.life--;

            const alpha = Math.max(0, p.life / p.max);
            ctx.fillStyle = `hsla(${p.hue}, ${p.sat}%, ${p.light}%, ${alpha * 0.8})`;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * alpha * 1.6, 0, Math.PI * 2);
            ctx.fill();

            if (p.life <= 0) motes.splice(i, 1);
        }

        // EXPANDING RIPPLES
        for (let i = ripples.length - 1; i >= 0; i--) {
            const r = ripples[i];
            r.r += 2.1;
            r.life -= 0.012;

            ctx.beginPath();
            ctx.ellipse(r.x, r.y, r.r, r.r * 0.68, 0, 0, Math.PI * 2);
            ctx.strokeStyle = `hsla(${hue + 20}, 62%, 80%, ${Math.max(0, r.life) * 0.4})`;
            ctx.lineWidth = 1.2;
            ctx.stroke();

            if (r.life <= 0) ripples.splice(i, 1);
        }

        rafId = running ? requestAnimationFrame(frame) : 0;
    }

    function startLoop() {
        if (running || reducedMotion()) return;
        running = true;
        rafId = requestAnimationFrame(frame);
    }

    function stopLoop() {
        running = false;
        if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = 0;
        }
    }

    // POINTER — document/window level, coordinates straight from
    // clientX/clientY (fixed full-viewport canvas, no rect math).
    function trackPointer(e) {
        pointer.x = e.clientX;
        pointer.y = e.clientY;
        pointer.active = true;
    }

    function onDown(e) {
        // bursts behind the opaque panel would be invisible — skip them
        if (e.target && e.target.closest && e.target.closest('.login-panel')) return;
        trackPointer(e);
        makeBloom(pointer.x, pointer.y, 0.85);
    }

    function onLeave() {
        pointer.active = false;
    }

    // One static frame keeps the ambience without any motion.
    function renderStatic() {
        buildGradients();
        ctx.globalAlpha = 1;
        ctx.fillStyle = baseGrad;
        ctx.fillRect(0, 0, w, h);
        for (const s of stars) {
            ctx.fillStyle = `rgba(246, 231, 209, ${s.a * 0.6})`;
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
            ctx.fill();
        }
        drawCore(fx, fy, 2.5);
        const cx = fx;
        const cy = fy;
        for (let ring = 0; ring < 4; ring++) {
            ctx.beginPath();
            const radius = Math.min(w, h) * (0.19 + ring * 0.075) * focusScale;
            for (let i = 0; i <= 110; i++) {
                const a = (i / 110) * Math.PI * 2;
                const wobble = Math.sin(a * (5 + ring) + 3.5) * 8;
                const x = cx + Math.cos(a) * (radius + wobble);
                const y = cy + Math.sin(a) * (radius + wobble) * 0.62;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.strokeStyle = `hsla(${hue + ring * 14}, 58%, 78%, ${0.1 - ring * 0.012})`;
            ctx.lineWidth = 0.8;
            ctx.stroke();
        }
    }

    // INIT
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            resize();
            // reduced motion never has a loop — re-render the static frame
            if (!running) renderStatic();
        }, 150);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopLoop();
        else startLoop(); // guarded — never double-starts
    });

    resize();

    if (reducedMotion()) {
        renderStatic();
        return; // no loop, no pointer listeners
    }

    document.addEventListener('pointermove', trackPointer);
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('pointerleave', onLeave);
    window.addEventListener('blur', onLeave);

    makeBloom(fx, fy, 0.9);
    startLoop();
})();
