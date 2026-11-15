
/* =========================================================
   USER DROPDOWN
   ========================================================= */

if (!window.userDropdownListenerAttached) {
    window.userDropdownListenerAttached = true;

    document.addEventListener("click", event => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const clickedButton = target.closest(".user-menu-button");
        const userMenus = document.querySelectorAll(".user-menu");

        if (clickedButton) {
            event.preventDefault();
            event.stopPropagation();

            const clickedMenu = clickedButton.closest(".user-menu");
            if (!clickedMenu) return;

            const isOpen = clickedMenu.classList.contains("active");

            userMenus.forEach(menu => {
                menu.classList.remove("active");
                menu.querySelector(".user-menu-button")
                    ?.setAttribute("aria-expanded", "false");
            });

            if (!isOpen) {
                clickedMenu.classList.add("active");
                clickedButton.setAttribute("aria-expanded", "true");
                loadCustomerNotifications();
            }

            return;
        }

        userMenus.forEach(menu => {
            if (!menu.contains(target)) {
                menu.classList.remove("active");
                menu.querySelector(".user-menu-button")
                    ?.setAttribute("aria-expanded", "false");
            }
        });
    }, true);
}


/* =========================================================
   CUSTOMER NOTIFICATIONS
   ========================================================= */

async function loadCustomerNotifications() {
    const container = document.getElementById("customerNotifications");
    const list = document.getElementById("notificationList");
    const count = document.getElementById("notificationNewCount");

    if (!container || !list) return;

    const url = container.dataset.notificationsUrl;

    if (!url) return;

    list.textContent = "Loading notifications...";

    try {
        const response = await fetch(url, {
            method: "GET",
            headers: { "Accept": "application/json" },
            credentials: "same-origin"
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Unable to load notifications.");
        }

        if (count) {
            count.textContent = Number(data.unread_count) > 0
                ? `${data.unread_count} NEW`
                : "";
        }

        updateNotificationBadge(data.unread_count);

        list.replaceChildren();

        if (!data.notifications || data.notifications.length === 0) {
            const empty = document.createElement("p");
            empty.textContent = "No order notifications yet.";
            empty.style.cssText =
                "margin:0;padding:8px 0;font-size:12px;opacity:.7;line-height:1.5;";
            list.appendChild(empty);
            return;
        }

        data.notifications.forEach(notification => {
            list.appendChild(createNotificationElement(notification));
        });

    } catch (error) {
        console.error("Notification loading error:", error);
        list.textContent = "Unable to load notifications. Please try again.";
    }
}


function createNotificationElement(notification) {
    const isUnread = Number(notification.is_read) === 0;

    const item = document.createElement("div");
    item.className = "customer-notification-item";

    item.style.cssText = `
        display:flex;
        align-items:flex-start;
        gap:8px;
        padding:9px 10px;
        margin-top:6px;
        border-radius:10px;
        background:${isUnread ? "rgba(217,54,54,.10)" : "rgba(255,255,255,.035)"};
        border-left:3px solid ${isUnread ? "#d93636" : "rgba(215,215,215,.45)"};
    `;

    const content = document.createElement("button");
    content.type = "button";
    content.className = "notification-content";
    content.style.cssText = `
        display:block;
        flex:1;
        min-width:0;
        padding:0;
        border:0;
        background:transparent;
        color:inherit;
        text-align:left;
        cursor:pointer;
        overflow-wrap:anywhere;
    `;

    const title = document.createElement("strong");
    title.textContent = notification.title || "Order Update";
    title.style.cssText = "display:block;font-size:12px;line-height:1.35;";

    const message = document.createElement("span");
    message.textContent = notification.message || "";
    message.style.cssText =
        "display:block;margin-top:3px;font-size:12px;line-height:1.45;overflow-wrap:anywhere;";

    const date = document.createElement("small");
    date.textContent = formatNotificationDate(notification.created_at);
    date.style.cssText = "display:block;margin-top:4px;font-size:10px;opacity:.7;";

    content.append(title, message, date);

    content.addEventListener("click", async () => {
        if (!isUnread) {
            window.location.href = "/cart#my-orders";
            return;
        }

        try {
            await submitNotificationAction(
                `/notifications/${notification.id}/read`,
                notification.read_token
            );

            await loadCustomerNotifications();
            window.location.href = "/cart#my-orders";

        } catch (error) {
            alert(error.message);
        }
    });

    const remove = document.createElement("button");
    remove.type = "button";
    remove.textContent = "×";
    remove.title = "Remove notification";
    remove.setAttribute("aria-label", "Remove notification");
    remove.style.cssText = `
        flex:0 0 auto;
        width:25px;
        height:25px;
        padding:0;
        border:1px solid rgba(255,255,255,.15);
        border-radius:50%;
        background:rgba(255,255,255,.06);
        color:#ffd8cf;
        font-size:16px;
        cursor:pointer;
    `;

    remove.addEventListener("click", async () => {
        if (!confirm("Remove this notification? This will not delete your order.")) {
            return;
        }

        try {
            await submitNotificationAction(
                `/notifications/${notification.id}/delete`,
                notification.delete_token
            );

            await loadCustomerNotifications();

        } catch (error) {
            alert(error.message);
        }
    });

    item.append(content, remove);

    return item;
}


async function submitNotificationAction(url, token) {
    if (!token) {
        throw new Error("Security token is missing. Please refresh the page.");
    }

    const formData = new FormData();
    formData.append("_token", token);

    const response = await fetch(url, {
        method: "POST",
        body: formData,
        headers: { "Accept": "application/json" },
        credentials: "same-origin"
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(data.message || "Notification action failed.");
    }

    return data;
}


function updateNotificationBadge(unreadCount) {
    const button = document.querySelector(".user-menu-button");
    if (!button) return;

    let badge = button.querySelector(".notification-badge");
    const count = Number(unreadCount) || 0;

    if (count <= 0) {
        badge?.remove();
        return;
    }

    if (!badge) {
        badge = document.createElement("span");
        badge.className = "notification-badge";
        badge.style.cssText = `
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:17px;
            height:17px;
            padding:0 4px;
            border-radius:20px;
            background:#d93636;
            color:white;
            font-size:10px;
            font-weight:700;
        `;
        button.appendChild(badge);
    }

    badge.textContent = count > 99 ? "99+" : String(count);
}


function formatNotificationDate(value) {
    if (!value) return "";

    const date = new Date(String(value).replace(" ", "T"));

    if (Number.isNaN(date.getTime())) return "";

    return date.toLocaleString([], {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit"
    });
}


/* =========================================================
   OTHER WEBSITE FUNCTIONS
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {

    const header = document.getElementById("siteHeader");
    const toggle = document.querySelector(".menu-toggle");
    const nav = document.querySelector(".main-nav");
    

    /* MOBILE NAVIGATION */

    toggle?.addEventListener("click", () => {
        const open = nav?.classList.toggle("open") ?? false;
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    document.querySelectorAll(".main-nav a").forEach(link => {
        link.addEventListener("click", () => {
            nav?.classList.remove("open");
            toggle?.setAttribute("aria-expanded", "false");
        });
    });

    /* HEADER SCROLL EFFECT */

    window.addEventListener("scroll", () => {
        header?.classList.toggle("scrolled", window.scrollY > 20);
    });

    /* FOOTER YEAR */

    const year = document.getElementById("year");
    if (year) year.textContent = new Date().getFullYear();

    /* PROFILE POPUP */

    if (!window.profileModalListenerAttached) {
        window.profileModalListenerAttached = true;

        document.addEventListener("click", event => {
            const target = event.target;
            if (!(target instanceof Element)) return;

            const openButton = target.closest(".profile-open-btn");

            if (openButton) {
                event.preventDefault();
                event.stopPropagation();

                const modal = document.getElementById("profileModal");
                if (!modal) return;

                document.querySelectorAll(".user-menu").forEach(menu => {
                    menu.classList.remove("active");
                    menu.querySelector(".user-menu-button")
                        ?.setAttribute("aria-expanded", "false");
                });

                modal.classList.add("active");
                modal.setAttribute("aria-hidden", "false");
                document.body.style.overflow = "hidden";
                return;
            }

            if (
                target.closest("#profileModalClose") ||
                target.closest(".profile-modal-overlay")
            ) {
                closeProfileModal();
            }
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") closeProfileModal();
        });
    }

    /* CARD REVEAL ANIMATION */

    const cards = document.querySelectorAll(".burger-card, .gallery-card");

    cards.forEach(card => {
        card.style.opacity = "0";
    });

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;

                entry.target.animate(
                    [
                        { opacity: 0, transform: "translateY(14px)" },
                        { opacity: 1, transform: "translateY(0)" }
                    ],
                    {
                        duration: 450,
                        easing: "ease-out",
                        fill: "forwards"
                    }
                );

                observer.unobserve(entry.target);
            });
        }, { threshold: 0.08 });

        cards.forEach(card => observer.observe(card));
    }
});


function closeProfileModal() {
    const modal = document.getElementById("profileModal");
    if (!modal) return;

    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
}
