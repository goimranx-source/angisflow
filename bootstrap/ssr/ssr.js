import { jsx, jsxs, Fragment } from "react/jsx-runtime";
import { usePage, Head, Link, router, useForm, createInertiaApp } from "@inertiajs/react";
import { QueryClient, useQuery, useQueryClient, QueryClientProvider } from "@tanstack/react-query";
import { X, Wrench, WarningDiamond, Warning, Warehouse, Wallet, UsersThree, UserPlus, UserFocus, User, Truck, TreeStructure, Target, Tag, Storefront, SquaresFour, SpinnerGap, Sparkle, SignOut, ShoppingCart, ShoppingBagOpen, ShieldCheck, SealCheck, Scales, ReceiptX, Receipt, PushPin, Plus, Package, Notebook, Megaphone, Medal, MagnifyingGlass, ListMagnifyingGlass, List, Key, Info, House, Handshake, GraduationCap, GearSix, Gear, FlowArrow, FileMagnifyingGlass, Factory, EyeSlash, Eye, Circle, ClockUser, Check, ChatsCircle, ChatText, ChartLineUp, ChartLine, CashRegister, CaretUpDown, CaretRight, CaretLeft, CalendarCheck, Buildings, ArrowUDownLeft, AddressBook } from "@phosphor-icons/react";
import { forwardRef, useId, useState, useRef, useEffect, useMemo, useCallback } from "react";
import { clsx } from "clsx";
import { twMerge } from "tailwind-merge";
import createServer from "@inertiajs/react/server";
import { renderToString } from "react-dom/server";
import { createPortal } from "react-dom";
const REGISTRY = {
  "address-book": AddressBook,
  "arrow-u-down-left": ArrowUDownLeft,
  buildings: Buildings,
  "calendar-check": CalendarCheck,
  "caret-left": CaretLeft,
  "caret-right": CaretRight,
  "caret-up-down": CaretUpDown,
  "cash-register": CashRegister,
  "chart-line": ChartLine,
  "chart-line-up": ChartLineUp,
  "chat-text": ChatText,
  "chats-circle": ChatsCircle,
  check: Check,
  "clock-user": ClockUser,
  dot: Circle,
  eye: Eye,
  "eye-slash": EyeSlash,
  factory: Factory,
  "file-magnifying-glass": FileMagnifyingGlass,
  "flow-arrow": FlowArrow,
  gear: Gear,
  "gear-six": GearSix,
  "graduation-cap": GraduationCap,
  handshake: Handshake,
  house: House,
  info: Info,
  key: Key,
  list: List,
  "list-magnifying-glass": ListMagnifyingGlass,
  "magnifying-glass": MagnifyingGlass,
  medal: Medal,
  megaphone: Megaphone,
  notebook: Notebook,
  package: Package,
  plus: Plus,
  "push-pin": PushPin,
  receipt: Receipt,
  "receipt-x": ReceiptX,
  scales: Scales,
  "seal-check": SealCheck,
  "shield-check": ShieldCheck,
  "shopping-bag-open": ShoppingBagOpen,
  "shopping-cart": ShoppingCart,
  "sign-out": SignOut,
  sparkle: Sparkle,
  spinner: SpinnerGap,
  "squares-four": SquaresFour,
  storefront: Storefront,
  tag: Tag,
  target: Target,
  "tree-structure": TreeStructure,
  truck: Truck,
  user: User,
  "user-focus": UserFocus,
  "user-plus": UserPlus,
  "users-three": UsersThree,
  wallet: Wallet,
  warehouse: Warehouse,
  warning: Warning,
  "warning-diamond": WarningDiamond,
  wrench: Wrench,
  x: X
};
function Icon({ name, size = 18, weight = "bold", className }) {
  const Component = REGISTRY[name] ?? Circle;
  return /* @__PURE__ */ jsx(Component, { size, weight, className, "aria-hidden": true });
}
function PageHeader({ title, description, actions }) {
  return /* @__PURE__ */ jsxs("div", { className: "mb-6 flex flex-wrap items-start justify-between gap-4", children: [
    /* @__PURE__ */ jsxs("div", { className: "min-w-0", children: [
      /* @__PURE__ */ jsx("h1", { className: "text-2xl font-bold tracking-[-0.02em]", children: title }),
      description && /* @__PURE__ */ jsx("p", { className: "mt-1 text-sm text-[var(--color-text-muted)]", children: description })
    ] }),
    actions && /* @__PURE__ */ jsx("div", { className: "flex flex-none items-center gap-2", children: actions })
  ] });
}
function Skeleton({ className }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      className: `animate-pulse rounded-lg bg-[var(--color-brand-subtle)] ${className ?? ""}`,
      "aria-hidden": true
    }
  );
}
function useShared() {
  return usePage().props;
}
class ApiError extends Error {
  constructor(status, message, errors = {}, payload = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.payload = payload;
    this.name = "ApiError";
  }
  status;
  errors;
  payload;
  /** The first message for a field, which is all a form ever shows. */
  fieldError(field) {
    return this.errors[field]?.[0];
  }
  get isValidation() {
    return this.status === 422;
  }
  /** Signed out, or the session expired while the tab sat open. */
  get isUnauthenticated() {
    return this.status === 401 || this.status === 419;
  }
  get isForbidden() {
    return this.status === 403;
  }
}
function csrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  if (match?.[1]) {
    return decodeURIComponent(match[1]);
  }
  return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}
async function request(method, path, body, options = {}) {
  const url = new URL(path.startsWith("/") ? path : `/api/v1/${path}`, window.location.origin);
  for (const [key, value] of Object.entries(options.params ?? {})) {
    if (value !== null && value !== void 0 && value !== "") {
      url.searchParams.set(key, String(value));
    }
  }
  const isWrite = method !== "GET" && method !== "HEAD";
  const response = await fetch(url, {
    method,
    credentials: "same-origin",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...isWrite ? { "Content-Type": "application/json", "X-XSRF-TOKEN": csrfToken() } : {}
    },
    body: isWrite && body !== void 0 ? JSON.stringify(body) : void 0
  });
  if (response.status === 204) {
    return void 0;
  }
  const text = await response.text();
  const payload = text ? safeParse(text) : null;
  if (!response.ok) {
    const asRecord = payload ?? {};
    throw new ApiError(
      response.status,
      asRecord.message ?? `Request failed (${response.status})`,
      asRecord.errors ?? {},
      payload
    );
  }
  return payload;
}
function safeParse(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}
const api = {
  get: (path, options) => request("GET", path, void 0, options),
  post: (path, body, options) => request("POST", path, body, options),
  patch: (path, body, options) => request("PATCH", path, body, options),
  put: (path, body, options) => request("PUT", path, body, options),
  delete: (path, options) => request("DELETE", path, void 0, options)
};
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 3e4,
      gcTime: 5 * 6e4,
      // The window regaining focus is not evidence anything changed, and
      // refetching on it means a user with the tab open all day generates
      // a request every time they alt-tab. Reconnecting is different —
      // that one genuinely implies missed time.
      refetchOnWindowFocus: false,
      refetchOnReconnect: true,
      refetchOnMount: false,
      retry: (failureCount, error) => {
        if (error instanceof ApiError) {
          if (error.status < 500 && error.status !== 429) {
            return false;
          }
        }
        return failureCount < 2;
      },
      // Backs off rather than hammering a server that is already
      // struggling — which is how a brief wobble becomes an outage.
      retryDelay: (attempt) => Math.min(1e3 * 2 ** attempt, 8e3)
    },
    mutations: {
      retry: false
    }
  }
});
const keys = {
  dashboard: {
    all: ["dashboard"],
    summary: (period) => ["dashboard", "summary", period]
  }
};
function resetCacheForBusinessSwitch() {
  queryClient.clear();
}
function Dashboard() {
  const { tenant } = useShared();
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: keys.dashboard.summary("this_month"),
    queryFn: ({ signal }) => api.get("dashboard", { params: { period: "this_month" }, signal })
  });
  const summary = data?.data;
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: "Dashboard" }),
    /* @__PURE__ */ jsx(
      PageHeader,
      {
        title: `Good ${greeting()}`,
        description: tenant?.business ? `${tenant.business.name} — the whole business at a glance.` : "The whole business at a glance."
      }
    ),
    isError && /* @__PURE__ */ jsxs(
      "div",
      {
        className: "card mb-6 flex items-center justify-between gap-4 p-4 text-sm",
        style: { background: "var(--color-danger-subtle)", color: "var(--color-danger-text)" },
        role: "alert",
        children: [
          /* @__PURE__ */ jsx("span", { children: "Those figures could not be loaded." }),
          /* @__PURE__ */ jsx("button", { type: "button", onClick: () => void refetch(), className: "font-semibold underline", children: "Try again" })
        ]
      }
    ),
    /* @__PURE__ */ jsxs("div", { className: "grid gap-4 sm:grid-cols-2 lg:grid-cols-4", children: [
      /* @__PURE__ */ jsx(
        Tile,
        {
          label: "Businesses",
          icon: "buildings",
          value: summary?.account.businesses,
          loading: isPending
        }
      ),
      /* @__PURE__ */ jsx(Tile, { label: "Team", icon: "users-three", value: summary?.account.team, loading: isPending }),
      /* @__PURE__ */ jsx(
        Tile,
        {
          label: "Currency",
          icon: "wallet",
          value: summary?.currency,
          loading: isPending
        }
      ),
      /* @__PURE__ */ jsx(
        Tile,
        {
          label: "Plan",
          icon: "sparkle",
          value: summary?.account.status,
          loading: isPending
        }
      )
    ] }),
    summary && !summary.ready && /* @__PURE__ */ jsx("div", { className: "card mt-6 p-6", children: /* @__PURE__ */ jsxs("div", { className: "flex items-start gap-4", children: [
      /* @__PURE__ */ jsx("span", { className: "grid size-10 flex-none place-items-center rounded-xl bg-[var(--color-brand-subtle)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: "sparkle", size: 20 }) }),
      /* @__PURE__ */ jsxs("div", { className: "min-w-0", children: [
        /* @__PURE__ */ jsx("h2", { className: "text-base font-bold", children: "Nothing to report yet" }),
        /* @__PURE__ */ jsx("p", { className: "mt-1 text-sm text-[var(--color-text-muted)]", children: "Income, expenses, orders and what is owed appear here once the trading modules are connected. Everything that is mapped out is on the roadmap, and each entry says what it will do." }),
        /* @__PURE__ */ jsxs(
          Link,
          {
            href: "/roadmap",
            prefetch: "hover",
            className: "mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--color-link)]",
            children: [
              "See the roadmap",
              /* @__PURE__ */ jsx(Icon, { name: "caret-right", size: 13 })
            ]
          }
        )
      ] })
    ] }) })
  ] });
}
function Tile({ label, icon, value, loading }) {
  return /* @__PURE__ */ jsxs("div", { className: "card p-5", children: [
    /* @__PURE__ */ jsxs("div", { className: "flex items-center justify-between", children: [
      /* @__PURE__ */ jsx("p", { className: "text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase", children: label }),
      /* @__PURE__ */ jsx(Icon, { name: icon, size: 16, className: "text-[var(--color-text-subtle)]" })
    ] }),
    loading ? /* @__PURE__ */ jsx(Skeleton, { className: "mt-3 h-8 w-20" }) : /* @__PURE__ */ jsx("p", { className: "mt-2 text-3xl font-bold tracking-[-0.02em] text-[var(--color-text-main)] capitalize", children: value ?? "—" })
  ] });
}
function greeting() {
  const hour = (/* @__PURE__ */ new Date()).getHours();
  if (hour < 12) {
    return "morning";
  }
  return hour < 17 ? "afternoon" : "evening";
}
const __vite_glob_0_0 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Dashboard
}, Symbol.toStringTag, { value: "Module" }));
function cn(...inputs) {
  return twMerge(clsx(inputs));
}
function pathMatches(current, prefixes) {
  const path = current.split("?")[0]?.replace(/\/+$/, "") || "/";
  return prefixes.some((prefix) => {
    const clean = prefix.split("?")[0]?.replace(/\/+$/, "") || "/";
    if (clean === "/") {
      return path === "/";
    }
    return path === clean || path.startsWith(`${clean}/`);
  });
}
function initials(name) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) {
    return "?";
  }
  if (parts.length === 1) {
    return (parts[0] ?? "").slice(0, 2).toUpperCase();
  }
  return ((parts[0]?.[0] ?? "") + (parts[parts.length - 1]?.[0] ?? "")).toUpperCase();
}
function setCookie(name, value, days = 365) {
  const expires = new Date(Date.now() + days * 864e5).toUTCString();
  document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
}
function getCookie(name) {
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}
const Button = forwardRef(function Button2({ variant = "primary", size = "md", loading = false, block = false, className, children, disabled, ...props }, ref) {
  return /* @__PURE__ */ jsxs(
    "button",
    {
      ref,
      disabled: disabled || loading,
      "aria-busy": loading || void 0,
      className: cn(
        "btn",
        `btn-${variant}`,
        size === "sm" && "px-3 py-1.5 text-[0.8125rem]",
        block && "w-full",
        className
      ),
      ...props,
      children: [
        loading && /* @__PURE__ */ jsx(Icon, { name: "spinner", size: 16, className: "animate-spin" }),
        children
      ]
    }
  );
});
const MESSAGES = {
  403: {
    title: "Not your page",
    body: "Your role does not open this one. If it should, whoever manages your account can add it."
  },
  404: {
    title: "Nothing here",
    body: "That address does not point at anything. It may have moved, or the link may be old."
  },
  429: {
    title: "Too fast",
    body: "That has been tried a few too many times. Give it a minute and go again."
  },
  500: {
    title: "Something broke",
    body: "Our end, not yours. It has been logged and somebody will see it."
  },
  503: {
    title: "Back shortly",
    body: "Prism is being updated. This usually takes less than a minute."
  }
};
function ErrorPage({ status }) {
  const message = MESSAGES[status] ?? MESSAGES[500];
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: message.title }),
    /* @__PURE__ */ jsxs("div", { className: "mx-auto flex max-w-md flex-col items-center py-20 text-center", children: [
      /* @__PURE__ */ jsx("span", { className: "grid size-14 place-items-center rounded-2xl bg-[var(--color-brand-subtle)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: "warning-diamond", size: 26 }) }),
      /* @__PURE__ */ jsx("p", { className: "mt-5 font-[family-name:var(--font-mono)] text-sm text-[var(--color-text-muted)]", children: status }),
      /* @__PURE__ */ jsx("h1", { className: "mt-1 text-2xl font-bold", children: message.title }),
      /* @__PURE__ */ jsx("p", { className: "mt-2 text-sm text-[var(--color-text-muted)]", children: message.body }),
      /* @__PURE__ */ jsxs("div", { className: "mt-6 flex gap-2", children: [
        /* @__PURE__ */ jsx(Button, { variant: "secondary", onClick: () => window.history.back(), children: "Go back" }),
        /* @__PURE__ */ jsx(Button, { onClick: () => router.visit("/dashboard"), children: "Dashboard" })
      ] })
    ] })
  ] });
}
const __vite_glob_0_1 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: ErrorPage
}, Symbol.toStringTag, { value: "Module" }));
const Field = forwardRef(function Field2({ label, error, hint, className, type = "text", ...props }, ref) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;
  const [revealed, setRevealed] = useState(false);
  const isPassword = type === "password";
  return /* @__PURE__ */ jsxs("div", { className: "space-y-1.5", children: [
    /* @__PURE__ */ jsx("label", { htmlFor: id, className: "block text-[0.8125rem] font-semibold text-[var(--color-text-main)]", children: label }),
    /* @__PURE__ */ jsxs("div", { className: "relative", children: [
      /* @__PURE__ */ jsx(
        "input",
        {
          ref,
          id,
          type: isPassword && revealed ? "text" : type,
          "aria-invalid": error ? "true" : void 0,
          "aria-describedby": cn(error && errorId, hint && hintId) || void 0,
          className: cn("field", isPassword && "pr-11", className),
          ...props
        }
      ),
      isPassword && /* @__PURE__ */ jsx(
        "button",
        {
          type: "button",
          onClick: () => setRevealed((was) => !was),
          tabIndex: -1,
          "aria-label": revealed ? "Hide password" : "Show password",
          className: "absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)]",
          children: /* @__PURE__ */ jsx(Icon, { name: revealed ? "eye-slash" : "eye", size: 17, weight: "regular" })
        }
      )
    ] }),
    hint && !error && /* @__PURE__ */ jsx("p", { id: hintId, className: "text-xs text-[var(--color-text-muted)]", children: hint }),
    error && /* @__PURE__ */ jsx("p", { id: errorId, role: "alert", className: "text-xs font-medium text-[var(--color-danger-text)]", children: error })
  ] });
});
function GuestLayout({ title, heading, subheading, children, footer }) {
  const { app } = useShared();
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title }),
    /* @__PURE__ */ jsxs("div", { className: "flex min-h-screen", children: [
      /* @__PURE__ */ jsx("div", { className: "flex w-full flex-col justify-center px-5 py-10 sm:px-10 lg:w-[54%] lg:px-16", children: /* @__PURE__ */ jsxs("div", { className: "mx-auto w-full max-w-[26rem]", children: [
        /* @__PURE__ */ jsxs("div", { className: "mb-8 flex items-center gap-2.5", children: [
          /* @__PURE__ */ jsx("span", { className: "grid size-9 place-items-center rounded-xl bg-[var(--color-brand)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: "sparkle", size: 20, weight: "fill" }) }),
          /* @__PURE__ */ jsx("span", { className: "font-[family-name:var(--font-heading)] text-xl font-bold text-[var(--color-text-main)]", children: app.name })
        ] }),
        /* @__PURE__ */ jsx("h1", { className: "text-2xl font-bold tracking-[-0.02em]", children: heading }),
        subheading && /* @__PURE__ */ jsx("p", { className: "mt-1.5 text-sm text-[var(--color-text-muted)]", children: subheading }),
        /* @__PURE__ */ jsx("div", { className: "mt-7", children }),
        footer && /* @__PURE__ */ jsx("div", { className: "mt-7 border-t border-[var(--color-border-light)] pt-5 text-sm text-[var(--color-text-muted)]", children: footer })
      ] }) }),
      /* @__PURE__ */ jsxs(
        "div",
        {
          className: "relative hidden lg:flex lg:w-[46%] lg:flex-col lg:justify-center lg:px-16",
          style: { background: "var(--color-ink)", color: "var(--color-text-on-dark)" },
          children: [
            /* @__PURE__ */ jsxs("div", { className: "max-w-md", children: [
              /* @__PURE__ */ jsx("h2", { className: "font-[family-name:var(--font-heading)] text-3xl leading-tight font-bold text-[var(--color-text-on-dark)]", children: app.tagline }),
              /* @__PURE__ */ jsx("ul", { className: "mt-8 space-y-4 text-sm text-white/70", children: [
                ["receipt", "Orders, money and stock in one ledger"],
                ["users-three", "Staff, payroll and who reports to whom"],
                ["storefront", "Every storefront you sell through"],
                ["chart-line", "Reports that agree with each other"]
              ].map(([icon, text]) => /* @__PURE__ */ jsxs("li", { className: "flex items-center gap-3", children: [
                /* @__PURE__ */ jsx("span", { className: "grid size-8 flex-none place-items-center rounded-[10px] bg-white/10", children: /* @__PURE__ */ jsx(Icon, { name: icon, size: 16 }) }),
                text
              ] }, text)) })
            ] }),
            /* @__PURE__ */ jsx(
              "div",
              {
                className: "pointer-events-none absolute -right-24 -bottom-24 size-72 rounded-full opacity-20 blur-3xl",
                style: { background: "var(--color-brand)" },
                "aria-hidden": true
              }
            )
          ]
        }
      )
    ] })
  ] });
}
function ConfirmPassword() {
  const form = useForm({ password: "" });
  const submit = (event) => {
    event.preventDefault();
    form.post("/confirm-password", {
      onFinish: () => form.reset("password")
    });
  };
  return /* @__PURE__ */ jsx(
    GuestLayout,
    {
      title: "Confirm password",
      heading: "Confirm it is you",
      subheading: "This is a change to how you sign in, so we ask for your password again.",
      children: /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Password",
            type: "password",
            name: "password",
            value: form.data.password,
            onChange: (event) => form.setData("password", event.target.value),
            error: form.errors.password,
            autoComplete: "current-password",
            autoFocus: true,
            required: true
          }
        ),
        /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Confirm" })
      ] })
    }
  );
}
ConfirmPassword.layout = void 0;
const __vite_glob_0_2 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: ConfirmPassword
}, Symbol.toStringTag, { value: "Module" }));
const SCRIPT_ID = "cf-turnstile-script";
function Turnstile({ siteKey }) {
  const container = useRef(null);
  useEffect(() => {
    if (!siteKey || !container.current) {
      return;
    }
    let widgetId;
    let cancelled = false;
    const render = () => {
      if (cancelled || !container.current || !window.turnstile) {
        return;
      }
      widgetId = window.turnstile.render(container.current, {
        sitekey: siteKey,
        theme: "light"
      });
    };
    if (window.turnstile) {
      render();
    } else if (!document.getElementById(SCRIPT_ID)) {
      const script = document.createElement("script");
      script.id = SCRIPT_ID;
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async = true;
      script.defer = true;
      script.onload = render;
      document.head.appendChild(script);
    } else {
      document.getElementById(SCRIPT_ID)?.addEventListener("load", render);
    }
    return () => {
      cancelled = true;
      if (widgetId && window.turnstile) {
        window.turnstile.remove(widgetId);
      }
    };
  }, [siteKey]);
  if (!siteKey) {
    return null;
  }
  return /* @__PURE__ */ jsx("div", { ref: container, className: "flex justify-center" });
}
function ForgotPassword({ status, turnstileSiteKey }) {
  const form = useForm({ email: "" });
  const submit = (event) => {
    event.preventDefault();
    form.post("/forgot-password");
  };
  return /* @__PURE__ */ jsxs(
    GuestLayout,
    {
      title: "Reset password",
      heading: "Reset your password",
      subheading: "Give us the address you sign in with and we will send a link.",
      footer: /* @__PURE__ */ jsx(Link, { href: "/login", className: "font-semibold text-[var(--color-link)]", children: "Back to sign in" }),
      children: [
        status && /* @__PURE__ */ jsx(
          "div",
          {
            className: "mb-5 rounded-xl px-4 py-3 text-sm font-medium",
            style: { background: "var(--color-success-subtle)", color: "var(--color-success)" },
            role: "status",
            children: status
          }
        ),
        /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
          /* @__PURE__ */ jsx(
            Field,
            {
              label: "Email",
              type: "email",
              name: "email",
              value: form.data.email,
              onChange: (event) => form.setData("email", event.target.value),
              error: form.errors.email,
              autoComplete: "username",
              autoFocus: true,
              required: true
            }
          ),
          /* @__PURE__ */ jsx(Turnstile, { siteKey: turnstileSiteKey }),
          /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Send the link" })
        ] })
      ]
    }
  );
}
ForgotPassword.layout = void 0;
const __vite_glob_0_3 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: ForgotPassword
}, Symbol.toStringTag, { value: "Module" }));
class PasskeyError extends Error {
  constructor(message, cause) {
    super(message);
    this.cause = cause;
    this.name = "PasskeyError";
  }
  cause;
}
function passkeysSupported() {
  return typeof window !== "undefined" && typeof window.PublicKeyCredential !== "undefined" && typeof navigator.credentials?.create === "function";
}
function toBuffer(value) {
  const base64 = value.replace(/-/g, "+").replace(/_/g, "/");
  const padded = base64.padEnd(base64.length + (4 - base64.length % 4) % 4, "=");
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}
function toBase64Url(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=/g, "");
}
function decodeDescriptors(list) {
  return (list ?? []).map((entry) => ({
    id: toBuffer(entry.id),
    type: "public-key",
    transports: entry.transports
  }));
}
async function registerPasskey(name) {
  if (!passkeysSupported()) {
    throw new PasskeyError("This browser does not support passkeys.");
  }
  const options = await api.post("/passkeys/options");
  let credential;
  try {
    credential = await navigator.credentials.create({
      publicKey: {
        ...options,
        challenge: toBuffer(options.challenge),
        user: { ...options.user, id: toBuffer(options.user.id) },
        excludeCredentials: decodeDescriptors(options.excludeCredentials)
      }
    });
  } catch (error) {
    throw new PasskeyError("That was cancelled, or the device refused.", error);
  }
  if (!credential) {
    throw new PasskeyError("No passkey was created.");
  }
  const response = credential.response;
  await api.post("/passkeys", {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
      attestationObject: toBase64Url(response.attestationObject)
    },
    name
  });
}
async function signInWithPasskey() {
  if (!passkeysSupported()) {
    throw new PasskeyError("This browser does not support passkeys.");
  }
  const options = await api.post("/passkey/login/options");
  let assertion;
  try {
    assertion = await navigator.credentials.get({
      publicKey: {
        ...options,
        challenge: toBuffer(options.challenge),
        allowCredentials: decodeDescriptors(options.allowCredentials)
      }
    });
  } catch (error) {
    throw new PasskeyError("That was cancelled, or no passkey matched.", error);
  }
  if (!assertion) {
    throw new PasskeyError("No passkey was offered.");
  }
  const response = assertion.response;
  const result = await api.post("/passkey/login", {
    id: assertion.id,
    rawId: toBase64Url(assertion.rawId),
    type: assertion.type,
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
      authenticatorData: toBase64Url(response.authenticatorData),
      signature: toBase64Url(response.signature),
      userHandle: response.userHandle ? toBase64Url(response.userHandle) : null
    }
  });
  return result.redirect;
}
function Login({ canRegister, status, turnstileSiteKey }) {
  const form = useForm({
    email: "",
    password: "",
    remember: false
  });
  const [passkeyBusy, setPasskeyBusy] = useState(false);
  const [passkeyError, setPasskeyError] = useState(null);
  const submit = (event) => {
    event.preventDefault();
    form.post("/login", {
      // The password is dropped from the form state whether the attempt
      // succeeded or failed. Leaving it there means it survives in memory,
      // in React DevTools and in any error reporter that serialises
      // component state — for as long as the tab is open.
      onFinish: () => form.reset("password")
    });
  };
  const withPasskey = async () => {
    setPasskeyError(null);
    setPasskeyBusy(true);
    try {
      window.location.href = await signInWithPasskey();
    } catch (error) {
      setPasskeyError(
        error instanceof PasskeyError ? error.message : "That did not work. Try your password."
      );
      setPasskeyBusy(false);
    }
  };
  return /* @__PURE__ */ jsxs(
    GuestLayout,
    {
      title: "Sign in",
      heading: "Sign in",
      subheading: "Pick up where the business left off.",
      footer: canRegister ? /* @__PURE__ */ jsxs(Fragment, { children: [
        "New here?",
        " ",
        /* @__PURE__ */ jsx(Link, { href: "/register", className: "font-semibold text-[var(--color-link)]", children: "Start a free trial" })
      ] }) : null,
      children: [
        status && /* @__PURE__ */ jsx(
          "div",
          {
            className: "mb-5 rounded-xl px-4 py-3 text-sm font-medium",
            style: { background: "var(--color-success-subtle)", color: "var(--color-success)" },
            role: "status",
            children: status
          }
        ),
        /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
          /* @__PURE__ */ jsx(
            Field,
            {
              label: "Email",
              type: "email",
              name: "email",
              value: form.data.email,
              onChange: (event) => form.setData("email", event.target.value),
              error: form.errors.email,
              autoComplete: "username",
              autoFocus: true,
              required: true
            }
          ),
          /* @__PURE__ */ jsx(
            Field,
            {
              label: "Password",
              type: "password",
              name: "password",
              value: form.data.password,
              onChange: (event) => form.setData("password", event.target.value),
              error: form.errors.password,
              autoComplete: "current-password",
              required: true
            }
          ),
          /* @__PURE__ */ jsxs("div", { className: "flex items-center justify-between", children: [
            /* @__PURE__ */ jsxs("label", { className: "flex cursor-pointer items-center gap-2 text-sm text-[var(--color-text-body)]", children: [
              /* @__PURE__ */ jsx(
                "input",
                {
                  type: "checkbox",
                  checked: form.data.remember,
                  onChange: (event) => form.setData("remember", event.target.checked),
                  className: "size-4 rounded border-[var(--color-border-strong)] accent-[var(--color-brand)]"
                }
              ),
              "Stay signed in"
            ] }),
            /* @__PURE__ */ jsx(Link, { href: "/forgot-password", className: "text-sm font-medium text-[var(--color-link)]", children: "Forgot password?" })
          ] }),
          /* @__PURE__ */ jsx(Turnstile, { siteKey: turnstileSiteKey }),
          /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Sign in" })
        ] }),
        passkeysSupported() && /* @__PURE__ */ jsxs(Fragment, { children: [
          /* @__PURE__ */ jsxs("div", { className: "my-6 flex items-center gap-3 text-xs text-[var(--color-text-muted)]", children: [
            /* @__PURE__ */ jsx("span", { className: "h-px flex-1 bg-[var(--color-border-light)]" }),
            "or",
            /* @__PURE__ */ jsx("span", { className: "h-px flex-1 bg-[var(--color-border-light)]" })
          ] }),
          /* @__PURE__ */ jsxs(Button, { variant: "secondary", block: true, loading: passkeyBusy, onClick: withPasskey, type: "button", children: [
            /* @__PURE__ */ jsx(Icon, { name: "key", size: 17, weight: "regular" }),
            "Use a passkey"
          ] }),
          passkeyError && /* @__PURE__ */ jsx("p", { role: "alert", className: "mt-2 text-center text-xs text-[var(--color-danger-text)]", children: passkeyError })
        ] })
      ]
    }
  );
}
Login.layout = void 0;
const __vite_glob_0_4 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Login
}, Symbol.toStringTag, { value: "Module" }));
function Register({ turnstileSiteKey }) {
  const form = useForm({
    name: "",
    business: "",
    email: "",
    password: "",
    password_confirmation: "",
    // Sent so the account is created in the timezone the person is actually
    // in. Every "today" in the tool — a daily journal, a dashboard range —
    // is read against it, and a business in Dhaka on a UTC server sees the
    // wrong day for the first six hours of every morning.
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
  });
  const submit = (event) => {
    event.preventDefault();
    form.post("/register", {
      onFinish: () => form.reset("password", "password_confirmation")
    });
  };
  return /* @__PURE__ */ jsx(
    GuestLayout,
    {
      title: "Start a trial",
      heading: "Start your free trial",
      subheading: "No card. Set up in a minute.",
      footer: /* @__PURE__ */ jsxs(Fragment, { children: [
        "Already have an account?",
        " ",
        /* @__PURE__ */ jsx(Link, { href: "/login", className: "font-semibold text-[var(--color-link)]", children: "Sign in" })
      ] }),
      children: /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Your name",
            name: "name",
            value: form.data.name,
            onChange: (event) => form.setData("name", event.target.value),
            error: form.errors.name,
            autoComplete: "name",
            autoFocus: true,
            required: true
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Business name",
            name: "business",
            value: form.data.business,
            onChange: (event) => form.setData("business", event.target.value),
            error: form.errors.business,
            autoComplete: "organization",
            hint: "You can change this later."
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Email",
            type: "email",
            name: "email",
            value: form.data.email,
            onChange: (event) => form.setData("email", event.target.value),
            error: form.errors.email,
            autoComplete: "username",
            required: true
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Password",
            type: "password",
            name: "password",
            value: form.data.password,
            onChange: (event) => form.setData("password", event.target.value),
            error: form.errors.password,
            autoComplete: "new-password",
            hint: "At least 10 characters. Length beats punctuation.",
            required: true
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Confirm password",
            type: "password",
            name: "password_confirmation",
            value: form.data.password_confirmation,
            onChange: (event) => form.setData("password_confirmation", event.target.value),
            error: form.errors.password_confirmation,
            autoComplete: "new-password",
            required: true
          }
        ),
        /* @__PURE__ */ jsx(Turnstile, { siteKey: turnstileSiteKey }),
        /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Create account" })
      ] })
    }
  );
}
Register.layout = void 0;
const __vite_glob_0_5 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Register
}, Symbol.toStringTag, { value: "Module" }));
function ResetPassword({ token, email }) {
  const form = useForm({
    token,
    email,
    password: "",
    password_confirmation: ""
  });
  const submit = (event) => {
    event.preventDefault();
    form.post("/reset-password", {
      onFinish: () => form.reset("password", "password_confirmation")
    });
  };
  return /* @__PURE__ */ jsx(
    GuestLayout,
    {
      title: "Choose a new password",
      heading: "Choose a new password",
      subheading: "Any other sessions will be signed out.",
      children: /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Email",
            type: "email",
            name: "email",
            value: form.data.email,
            onChange: (event) => form.setData("email", event.target.value),
            error: form.errors.email,
            autoComplete: "username",
            readOnly: Boolean(email),
            required: true
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "New password",
            type: "password",
            name: "password",
            value: form.data.password,
            onChange: (event) => form.setData("password", event.target.value),
            error: form.errors.password,
            autoComplete: "new-password",
            hint: "At least 10 characters, and not one that has appeared in a known breach.",
            autoFocus: true,
            required: true
          }
        ),
        /* @__PURE__ */ jsx(
          Field,
          {
            label: "Confirm new password",
            type: "password",
            name: "password_confirmation",
            value: form.data.password_confirmation,
            onChange: (event) => form.setData("password_confirmation", event.target.value),
            error: form.errors.password_confirmation,
            autoComplete: "new-password",
            required: true
          }
        ),
        /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Change password" })
      ] })
    }
  );
}
ResetPassword.layout = void 0;
const __vite_glob_0_6 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: ResetPassword
}, Symbol.toStringTag, { value: "Module" }));
function TwoFactorChallenge({ recoveryCodesLeft }) {
  const [useRecovery, setUseRecovery] = useState(false);
  const form = useForm({ code: "", recovery_code: "" });
  const submit = (event) => {
    event.preventDefault();
    form.transform(
      (data) => useRecovery ? { code: "", recovery_code: data.recovery_code } : { code: data.code, recovery_code: "" }
    );
    form.post("/two-factor/challenge", {
      onFinish: () => form.reset("code", "recovery_code")
    });
  };
  return /* @__PURE__ */ jsxs(
    GuestLayout,
    {
      title: "Two-factor",
      heading: "One more step",
      subheading: useRecovery ? "Enter one of the recovery codes you saved when you turned this on." : "Enter the six-digit code from your authenticator app.",
      footer: /* @__PURE__ */ jsx(
        "button",
        {
          type: "button",
          onClick: () => router.post("/logout"),
          className: "font-semibold text-[var(--color-link)]",
          children: "Sign in as somebody else"
        }
      ),
      children: [
        /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", noValidate: true, children: [
          useRecovery ? /* @__PURE__ */ jsx(
            Field,
            {
              label: "Recovery code",
              name: "recovery_code",
              value: form.data.recovery_code,
              onChange: (event) => form.setData("recovery_code", event.target.value),
              error: form.errors.recovery_code,
              autoComplete: "one-time-code",
              placeholder: "xxxxx-xxxxx",
              autoFocus: true,
              required: true
            }
          ) : /* @__PURE__ */ jsx(
            Field,
            {
              label: "Authentication code",
              name: "code",
              value: form.data.code,
              onChange: (event) => form.setData("code", event.target.value.replace(/\D/g, "")),
              error: form.errors.code,
              inputMode: "numeric",
              autoComplete: "one-time-code",
              maxLength: 6,
              placeholder: "000000",
              className: "field text-center font-[family-name:var(--font-mono)] text-2xl tracking-[0.4em]",
              autoFocus: true,
              required: true
            }
          ),
          /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, block: true, children: "Continue" })
        ] }),
        /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: () => {
              setUseRecovery((was) => !was);
              form.clearErrors();
            },
            className: "mt-4 w-full text-center text-sm font-medium text-[var(--color-link)]",
            children: useRecovery ? "Use my authenticator app instead" : "Use a recovery code instead"
          }
        ),
        useRecovery && /* @__PURE__ */ jsxs("p", { className: "mt-2 text-center text-xs text-[var(--color-text-muted)]", children: [
          recoveryCodesLeft,
          " ",
          recoveryCodesLeft === 1 ? "code" : "codes",
          " left. Each works once."
        ] })
      ]
    }
  );
}
TwoFactorChallenge.layout = void 0;
const __vite_glob_0_7 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: TwoFactorChallenge
}, Symbol.toStringTag, { value: "Module" }));
function VerifyEmail({ status, email }) {
  const form = useForm({});
  return /* @__PURE__ */ jsxs(
    GuestLayout,
    {
      title: "Verify your email",
      heading: "Check your inbox",
      subheading: /* @__PURE__ */ jsxs(Fragment, { children: [
        "We sent a link to ",
        /* @__PURE__ */ jsx("strong", { className: "text-[var(--color-text-main)]", children: email }),
        ". Open it to confirm the address is yours."
      ] }),
      footer: /* @__PURE__ */ jsx(
        "button",
        {
          type: "button",
          onClick: () => router.post("/logout"),
          className: "font-semibold text-[var(--color-link)]",
          children: "Sign out"
        }
      ),
      children: [
        status && /* @__PURE__ */ jsx(
          "div",
          {
            className: "mb-5 rounded-xl px-4 py-3 text-sm font-medium",
            style: { background: "var(--color-success-subtle)", color: "var(--color-success)" },
            role: "status",
            children: status
          }
        ),
        /* @__PURE__ */ jsxs("div", { className: "space-y-3", children: [
          /* @__PURE__ */ jsx(
            Button,
            {
              type: "button",
              loading: form.processing,
              block: true,
              onClick: () => form.post("/verify-email/resend"),
              children: "Send it again"
            }
          ),
          /* @__PURE__ */ jsx(Button, { variant: "ghost", block: true, onClick: () => router.visit("/dashboard"), children: "Continue for now" })
        ] })
      ]
    }
  );
}
VerifyEmail.layout = void 0;
const __vite_glob_0_8 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: VerifyEmail
}, Symbol.toStringTag, { value: "Module" }));
function Locked({ status, reason }) {
  return /* @__PURE__ */ jsx(
    GuestLayout,
    {
      title: "Account paused",
      heading: status === "cancelled" ? "This account was closed" : "This account is paused",
      subheading: reason ?? "Nothing has been deleted. Your data is exactly where you left it and comes back the moment this is sorted out.",
      footer: /* @__PURE__ */ jsx(
        "button",
        {
          type: "button",
          onClick: () => router.post("/logout"),
          className: "font-semibold text-[var(--color-link)]",
          children: "Sign out"
        }
      ),
      children: /* @__PURE__ */ jsx("div", { className: "space-y-3", children: /* @__PURE__ */ jsxs(Button, { block: true, onClick: () => router.visit("/billing"), children: [
        /* @__PURE__ */ jsx(Icon, { name: "wallet", size: 16, weight: "regular" }),
        "Open billing"
      ] }) })
    }
  );
}
Locked.layout = void 0;
const __vite_glob_0_9 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Locked
}, Symbol.toStringTag, { value: "Module" }));
function BillingShow({ subscription }) {
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: "Subscription" }),
    /* @__PURE__ */ jsx(PageHeader, { title: "Subscription", description: "What you are on, and when it renews." }),
    /* @__PURE__ */ jsx("div", { className: "card max-w-xl p-6", children: subscription ? /* @__PURE__ */ jsxs("dl", { className: "space-y-4", children: [
      /* @__PURE__ */ jsx(Row, { label: "Plan", value: subscription.plan ?? "—" }),
      /* @__PURE__ */ jsx(Row, { label: "Status", value: subscription.status }),
      /* @__PURE__ */ jsx(
        Row,
        {
          label: subscription.cancel_at_period_end ? "Ends" : "Renews",
          value: subscription.renews_at ? new Date(subscription.renews_at).toLocaleDateString() : "—"
        }
      )
    ] }) : /* @__PURE__ */ jsx("p", { className: "text-sm text-[var(--color-text-muted)]", children: "No subscription on this account yet." }) })
  ] });
}
function Row({ label, value }) {
  return /* @__PURE__ */ jsxs("div", { className: "flex items-center justify-between border-b border-[var(--color-border-light)] pb-3 last:border-0 last:pb-0", children: [
    /* @__PURE__ */ jsx("dt", { className: "text-sm text-[var(--color-text-muted)]", children: label }),
    /* @__PURE__ */ jsx("dd", { className: "text-sm font-semibold capitalize", children: value })
  ] });
}
const __vite_glob_0_10 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: BillingShow
}, Symbol.toStringTag, { value: "Module" }));
function Roadmap({ planned }) {
  const groups = useMemo(() => {
    const map = /* @__PURE__ */ new Map();
    for (const item of planned) {
      const existing = map.get(item.group);
      if (existing) {
        existing.items.push(item);
        continue;
      }
      map.set(item.group, { label: item.group_label ?? item.group, items: [item] });
    }
    return [...map.values()];
  }, [planned]);
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: "Roadmap" }),
    /* @__PURE__ */ jsx(
      PageHeader,
      {
        title: "Roadmap",
        description: `${planned.length} departments mapped out. Each one says what it is for and what it will do.`
      }
    ),
    /* @__PURE__ */ jsx("div", { className: "space-y-8", children: groups.map((group) => /* @__PURE__ */ jsxs("section", { children: [
      /* @__PURE__ */ jsx("h2", { className: "mb-3 text-[0.6875rem] font-semibold tracking-[0.12em] text-[var(--color-text-muted)] uppercase", children: group.label }),
      /* @__PURE__ */ jsx("div", { className: "grid gap-3 sm:grid-cols-2 lg:grid-cols-3", children: group.items.map((item) => /* @__PURE__ */ jsxs(
        Link,
        {
          href: `/soon/${item.key}`,
          prefetch: "hover",
          className: "card p-5 transition-shadow hover:shadow-[var(--shadow-md)]",
          children: [
            /* @__PURE__ */ jsx("span", { className: "grid size-9 place-items-center rounded-xl bg-[var(--color-brand-subtle)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: item.icon, size: 18 }) }),
            /* @__PURE__ */ jsx("p", { className: "mt-3 font-semibold text-[var(--color-text-main)]", children: item.label }),
            /* @__PURE__ */ jsx("p", { className: "mt-1 text-sm leading-relaxed text-[var(--color-text-muted)]", children: item.summary })
          ]
        },
        item.key
      )) })
    ] }, group.label)) })
  ] });
}
const __vite_glob_0_11 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Roadmap
}, Symbol.toStringTag, { value: "Module" }));
function Soon({ module }) {
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: module.label }),
    /* @__PURE__ */ jsx(
      PageHeader,
      {
        title: module.label,
        description: module.summary,
        actions: /* @__PURE__ */ jsx("span", { className: "chip bg-[var(--color-brand-subtle)] text-[var(--color-ink-soft)]", children: "Not built yet" })
      }
    ),
    /* @__PURE__ */ jsxs("div", { className: "grid gap-4 lg:grid-cols-3", children: [
      module.why && /* @__PURE__ */ jsxs("div", { className: "card p-6 lg:col-span-3", children: [
        /* @__PURE__ */ jsxs("h2", { className: "flex items-center gap-2 text-sm font-bold", children: [
          /* @__PURE__ */ jsx(Icon, { name: "info", size: 16, className: "text-[var(--color-text-muted)]" }),
          "Why this exists"
        ] }),
        /* @__PURE__ */ jsx("p", { className: "mt-2 text-sm leading-relaxed text-[var(--color-text-body)]", children: module.why })
      ] }),
      module.does && module.does.length > 0 && /* @__PURE__ */ jsxs("div", { className: "card p-6 lg:col-span-2", children: [
        /* @__PURE__ */ jsx("h2", { className: "text-sm font-bold", children: "What it will do" }),
        /* @__PURE__ */ jsx("ul", { className: "mt-3 space-y-2.5", children: module.does.map((line) => /* @__PURE__ */ jsxs("li", { className: "flex items-start gap-2.5 text-sm", children: [
          /* @__PURE__ */ jsx(
            Icon,
            {
              name: "check",
              size: 14,
              className: "mt-1 flex-none text-[var(--color-brand-active)]"
            }
          ),
          /* @__PURE__ */ jsx("span", { children: line })
        ] }, line)) })
      ] }),
      /* @__PURE__ */ jsxs("div", { className: "card p-6", children: [
        /* @__PURE__ */ jsx("h2", { className: "text-sm font-bold", children: "Where it sits" }),
        /* @__PURE__ */ jsxs("div", { className: "mt-3 flex items-center gap-3", children: [
          /* @__PURE__ */ jsx("span", { className: "grid size-10 place-items-center rounded-xl bg-[var(--color-brand-subtle)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: module.icon, size: 20 }) }),
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("p", { className: "text-sm font-semibold", children: module.label }),
            /* @__PURE__ */ jsx("p", { className: "text-xs text-[var(--color-text-muted)]", children: module.group_label })
          ] })
        ] }),
        /* @__PURE__ */ jsxs(
          Link,
          {
            href: "/roadmap",
            prefetch: "hover",
            className: "mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--color-link)]",
            children: [
              "See everything planned",
              /* @__PURE__ */ jsx(Icon, { name: "caret-right", size: 13 })
            ]
          }
        )
      ] })
    ] })
  ] });
}
const __vite_glob_0_12 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: Soon
}, Symbol.toStringTag, { value: "Module" }));
function ProfileShow({ timezones }) {
  const { auth } = useShared();
  if (!auth) {
    return null;
  }
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(Head, { title: "Profile & security" }),
    /* @__PURE__ */ jsx(PageHeader, { title: "Profile & security", description: "Your details, and how you sign in." }),
    /* @__PURE__ */ jsxs("div", { className: "grid gap-4 lg:grid-cols-2", children: [
      /* @__PURE__ */ jsx(DetailsCard, { timezones }),
      /* @__PURE__ */ jsx(PasswordCard, { hasPassword: true }),
      /* @__PURE__ */ jsx(TwoFactorCard, { enabled: auth.user.two_factor_enabled }),
      /* @__PURE__ */ jsx(PasskeysCard, {})
    ] })
  ] });
}
function sendToPasswordConfirmation(error) {
  if (error instanceof ApiError && error.status === 423) {
    router.visit("/confirm-password");
    return true;
  }
  return false;
}
function Card({ title, description, children }) {
  return /* @__PURE__ */ jsxs("section", { className: "card p-6", children: [
    /* @__PURE__ */ jsx("h2", { className: "text-base font-bold", children: title }),
    description && /* @__PURE__ */ jsx("p", { className: "mt-1 text-sm text-[var(--color-text-muted)]", children: description }),
    /* @__PURE__ */ jsx("div", { className: "mt-5", children })
  ] });
}
function DetailsCard({ timezones }) {
  const { auth } = useShared();
  const form = useForm({
    name: auth?.user.name ?? "",
    email: auth?.user.email ?? "",
    timezone: auth?.user.timezone ?? ""
  });
  const submit = (event) => {
    event.preventDefault();
    form.patch("/profile", { preserveScroll: true });
  };
  return /* @__PURE__ */ jsx(Card, { title: "Your details", children: /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", children: [
    /* @__PURE__ */ jsx(
      Field,
      {
        label: "Name",
        value: form.data.name,
        onChange: (event) => form.setData("name", event.target.value),
        error: form.errors.name,
        autoComplete: "name"
      }
    ),
    /* @__PURE__ */ jsx(
      Field,
      {
        label: "Email",
        type: "email",
        value: form.data.email,
        onChange: (event) => form.setData("email", event.target.value),
        error: form.errors.email,
        autoComplete: "email",
        hint: auth?.user.email_verified ? "Changing this means verifying the new address." : "This address has not been verified yet."
      }
    ),
    /* @__PURE__ */ jsxs("div", { className: "space-y-1.5", children: [
      /* @__PURE__ */ jsx("label", { htmlFor: "timezone", className: "block text-[0.8125rem] font-semibold text-[var(--color-text-main)]", children: "Timezone" }),
      /* @__PURE__ */ jsxs(
        "select",
        {
          id: "timezone",
          className: "field",
          value: form.data.timezone,
          onChange: (event) => form.setData("timezone", event.target.value),
          children: [
            /* @__PURE__ */ jsx("option", { value: "", children: "Use the account default" }),
            timezones.map((zone) => /* @__PURE__ */ jsx("option", { value: zone, children: zone }, zone))
          ]
        }
      ),
      /* @__PURE__ */ jsx("p", { className: "text-xs text-[var(--color-text-muted)]", children: "Every “today” in the tool is read against this." })
    ] }),
    /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, children: "Save" })
  ] }) });
}
function PasswordCard({ hasPassword }) {
  const form = useForm({
    current_password: "",
    password: "",
    password_confirmation: ""
  });
  const submit = (event) => {
    event.preventDefault();
    form.patch("/profile/password", {
      preserveScroll: true,
      onSuccess: () => form.reset()
    });
  };
  return /* @__PURE__ */ jsx(Card, { title: "Password", description: "Changing it signs out every other session.", children: /* @__PURE__ */ jsxs("form", { onSubmit: submit, className: "space-y-4", children: [
    hasPassword && /* @__PURE__ */ jsx(
      Field,
      {
        label: "Current password",
        type: "password",
        value: form.data.current_password,
        onChange: (event) => form.setData("current_password", event.target.value),
        error: form.errors.current_password,
        autoComplete: "current-password"
      }
    ),
    /* @__PURE__ */ jsx(
      Field,
      {
        label: "New password",
        type: "password",
        value: form.data.password,
        onChange: (event) => form.setData("password", event.target.value),
        error: form.errors.password,
        autoComplete: "new-password",
        hint: "At least 10 characters, and not one seen in a known breach."
      }
    ),
    /* @__PURE__ */ jsx(
      Field,
      {
        label: "Confirm new password",
        type: "password",
        value: form.data.password_confirmation,
        onChange: (event) => form.setData("password_confirmation", event.target.value),
        error: form.errors.password_confirmation,
        autoComplete: "new-password"
      }
    ),
    /* @__PURE__ */ jsx(Button, { type: "submit", loading: form.processing, children: "Change password" })
  ] }) });
}
function TwoFactorCard({ enabled }) {
  const [setup, setSetup] = useState(null);
  const [codes, setCodes] = useState(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const begin = async () => {
    setBusy(true);
    setError(null);
    try {
      setSetup(await api.post("/two-factor/enable"));
    } catch (caught) {
      if (!sendToPasswordConfirmation(caught)) {
        setError(caught instanceof ApiError ? caught.message : "That did not work.");
      }
    } finally {
      setBusy(false);
    }
  };
  const confirm = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = await api.post("/two-factor/confirm", { code });
      setCodes(result.recovery_codes);
      setSetup(null);
      router.reload({ only: ["auth"] });
    } catch (caught) {
      if (!sendToPasswordConfirmation(caught)) {
        setError(
          caught instanceof ApiError ? caught.fieldError("code") ?? caught.message : "That did not work."
        );
      }
    } finally {
      setBusy(false);
    }
  };
  return /* @__PURE__ */ jsxs(
    Card,
    {
      title: "Two-factor authentication",
      description: "A code from your phone, on top of your password.",
      children: [
        codes && /* @__PURE__ */ jsxs("div", { className: "mb-5 rounded-xl border border-[var(--color-brand-border)] bg-[var(--color-brand-subtle)] p-4", children: [
          /* @__PURE__ */ jsx("p", { className: "text-sm font-bold", children: "Save these recovery codes" }),
          /* @__PURE__ */ jsx("p", { className: "mt-1 text-xs text-[var(--color-text-muted)]", children: "Each works once, and this is the only time they are shown — only their hashes are stored, so there is nothing to show again." }),
          /* @__PURE__ */ jsx("ul", { className: "mt-3 grid grid-cols-2 gap-1.5 font-[family-name:var(--font-mono)] text-sm", children: codes.map((recovery) => /* @__PURE__ */ jsx("li", { children: recovery }, recovery)) }),
          /* @__PURE__ */ jsx(Button, { size: "sm", variant: "secondary", className: "mt-3", onClick: () => setCodes(null), children: "I have saved them" })
        ] }),
        enabled ? /* @__PURE__ */ jsxs("div", { className: "flex items-center justify-between gap-4", children: [
          /* @__PURE__ */ jsxs("span", { className: "chip bg-[var(--color-success-subtle)] text-[var(--color-success)]", children: [
            /* @__PURE__ */ jsx(Icon, { name: "check", size: 12, weight: "bold" }),
            "On"
          ] }),
          /* @__PURE__ */ jsx(
            Button,
            {
              variant: "danger",
              size: "sm",
              onClick: () => router.delete("/two-factor", {
                preserveScroll: true,
                onError: () => router.visit("/confirm-password")
              }),
              children: "Turn off"
            }
          )
        ] }) : setup ? /* @__PURE__ */ jsxs("form", { onSubmit: confirm, className: "space-y-4", children: [
          /* @__PURE__ */ jsx("p", { className: "text-sm", children: "Scan this with your authenticator app, then enter the code it shows." }),
          /* @__PURE__ */ jsx(
            "div",
            {
              className: "w-fit rounded-xl bg-white p-3",
              dangerouslySetInnerHTML: { __html: setup.qr }
            }
          ),
          /* @__PURE__ */ jsx("p", { className: "font-[family-name:var(--font-mono)] text-xs break-all text-[var(--color-text-muted)]", children: setup.secret }),
          /* @__PURE__ */ jsx(
            Field,
            {
              label: "Code from the app",
              value: code,
              onChange: (event) => setCode(event.target.value.replace(/\D/g, "")),
              error: error ?? void 0,
              inputMode: "numeric",
              maxLength: 6,
              placeholder: "000000"
            }
          ),
          /* @__PURE__ */ jsxs("div", { className: "flex gap-2", children: [
            /* @__PURE__ */ jsx(Button, { type: "submit", loading: busy, children: "Turn on" }),
            /* @__PURE__ */ jsx(Button, { type: "button", variant: "ghost", onClick: () => setSetup(null), children: "Cancel" })
          ] })
        ] }) : /* @__PURE__ */ jsxs(Fragment, { children: [
          /* @__PURE__ */ jsxs(Button, { onClick: begin, loading: busy, variant: "secondary", children: [
            /* @__PURE__ */ jsx(Icon, { name: "shield-check", size: 16, weight: "regular" }),
            "Set up"
          ] }),
          error && /* @__PURE__ */ jsx("p", { className: "mt-2 text-xs text-[var(--color-danger-text)]", children: error })
        ] })
      ]
    }
  );
}
function PasskeysCard() {
  const queryClient2 = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const { data, isPending } = useQuery({
    queryKey: ["passkeys"],
    queryFn: ({ signal }) => api.get("/passkeys", { signal })
  });
  const add = async () => {
    setBusy(true);
    setError(null);
    try {
      await registerPasskey();
      await queryClient2.invalidateQueries({ queryKey: ["passkeys"] });
    } catch (caught) {
      if (!sendToPasswordConfirmation(caught)) {
        setError(caught instanceof PasskeyError || caught instanceof ApiError ? caught.message : "That did not work.");
      }
    } finally {
      setBusy(false);
    }
  };
  const remove = async (id) => {
    try {
      await api.delete(`/passkeys/${id}`);
      await queryClient2.invalidateQueries({ queryKey: ["passkeys"] });
    } catch (caught) {
      if (!sendToPasswordConfirmation(caught)) {
        setError(caught instanceof ApiError ? caught.message : "That did not work.");
      }
    }
  };
  return /* @__PURE__ */ jsx(
    Card,
    {
      title: "Passkeys",
      description: "Your face, your fingerprint, or a hardware key. Cannot be phished.",
      children: !passkeysSupported() ? /* @__PURE__ */ jsx("p", { className: "text-sm text-[var(--color-text-muted)]", children: "This browser does not support passkeys." }) : /* @__PURE__ */ jsxs(Fragment, { children: [
        isPending ? /* @__PURE__ */ jsx("p", { className: "text-sm text-[var(--color-text-muted)]", children: "Loading…" }) : (data?.data.length ?? 0) === 0 ? /* @__PURE__ */ jsx("p", { className: "mb-4 text-sm text-[var(--color-text-muted)]", children: "No passkeys yet." }) : /* @__PURE__ */ jsx("ul", { className: "mb-4 divide-y divide-[var(--color-border-light)]", children: data?.data.map((passkey) => /* @__PURE__ */ jsxs("li", { className: "flex items-center justify-between gap-3 py-2.5", children: [
          /* @__PURE__ */ jsxs("span", { className: "flex min-w-0 items-center gap-2.5", children: [
            /* @__PURE__ */ jsx(Icon, { name: "key", size: 16, className: "flex-none text-[var(--color-text-muted)]" }),
            /* @__PURE__ */ jsxs("span", { className: "min-w-0", children: [
              /* @__PURE__ */ jsx("span", { className: "block truncate text-sm font-medium", children: passkey.name }),
              passkey.created_at && /* @__PURE__ */ jsxs("span", { className: "block text-xs text-[var(--color-text-muted)]", children: [
                "Added ",
                new Date(passkey.created_at).toLocaleDateString()
              ] })
            ] })
          ] }),
          /* @__PURE__ */ jsx(
            "button",
            {
              type: "button",
              onClick: () => void remove(passkey.id),
              className: "flex-none rounded-lg p-1.5 text-[var(--color-danger)] hover:bg-[var(--color-danger-subtle)]",
              "aria-label": `Remove ${passkey.name}`,
              children: /* @__PURE__ */ jsx(Icon, { name: "x", size: 14 })
            }
          )
        ] }, passkey.id)) }),
        /* @__PURE__ */ jsxs(Button, { variant: "secondary", onClick: add, loading: busy, children: [
          /* @__PURE__ */ jsx(Icon, { name: "plus", size: 15 }),
          "Add a passkey"
        ] }),
        error && /* @__PURE__ */ jsx("p", { className: "mt-2 text-xs text-[var(--color-danger-text)]", children: error })
      ] })
    }
  );
}
const __vite_glob_0_13 = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  default: ProfileShow
}, Symbol.toStringTag, { value: "Module" }));
function NavLink({ item, active, collapsed, child = false, pinned, onTogglePin }) {
  const handlePin = (event) => {
    event.preventDefault();
    event.stopPropagation();
    onTogglePin?.(item.key);
  };
  return /* @__PURE__ */ jsxs(
    Link,
    {
      href: item.href,
      prefetch: "hover",
      cacheFor: "1m",
      className: cn(child ? "nav-child" : "nav-row", active && "is-active"),
      "aria-current": active ? "page" : void 0,
      title: collapsed ? item.label : void 0,
      children: [
        /* @__PURE__ */ jsx("span", { className: child ? "flex-none opacity-60" : "nav-icon", children: /* @__PURE__ */ jsx(Icon, { name: item.icon, size: child ? 14 : 18 }) }),
        !collapsed && /* @__PURE__ */ jsx("span", { className: child ? "flex-1 truncate" : "nav-label", children: item.label }),
        !collapsed && !item.built && /* @__PURE__ */ jsx("span", { className: "flex-none rounded-full bg-white/10 px-1.5 py-0.5 text-[0.5625rem] font-semibold tracking-wide uppercase opacity-70", children: "Soon" }),
        !collapsed && onTogglePin && /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: handlePin,
            className: cn("nav-pin", pinned && "is-pinned"),
            "aria-label": pinned ? `Unpin ${item.label}` : `Pin ${item.label} to the top`,
            title: pinned ? "Unpin" : "Pin to the top",
            children: /* @__PURE__ */ jsx(Icon, { name: "push-pin", size: 13, weight: pinned ? "fill" : "regular" })
          }
        )
      ]
    }
  );
}
function ProfileMenu({ collapsed }) {
  const { auth } = useShared();
  const [open, setOpen] = useState(false);
  const container = useRef(null);
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointerDown = (event) => {
      if (!container.current?.contains(event.target)) {
        setOpen(false);
      }
    };
    const onKey = (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    };
    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);
  if (!auth) {
    return null;
  }
  const signOut = () => router.post("/logout");
  return /* @__PURE__ */ jsxs("div", { ref: container, className: "relative", children: [
    /* @__PURE__ */ jsxs(
      "button",
      {
        type: "button",
        onClick: () => setOpen((was) => !was),
        className: cn("nav-row", collapsed && "justify-center"),
        "aria-haspopup": "menu",
        "aria-expanded": open,
        title: collapsed ? auth.user.name : void 0,
        children: [
          /* @__PURE__ */ jsx("span", { className: "grid size-9 flex-none place-items-center rounded-full bg-[var(--color-brand)] text-[0.75rem] font-bold text-[var(--color-ink)]", children: initials(auth.user.name) }),
          !collapsed && /* @__PURE__ */ jsxs(Fragment, { children: [
            /* @__PURE__ */ jsxs("span", { className: "min-w-0 flex-1", children: [
              /* @__PURE__ */ jsx("span", { className: "block truncate text-[0.8125rem] font-semibold text-[var(--color-text-on-dark)]", children: auth.user.name }),
              /* @__PURE__ */ jsx("span", { className: "block truncate text-[0.6875rem] opacity-60", children: auth.user.email })
            ] }),
            /* @__PURE__ */ jsx(Icon, { name: "caret-up-down", size: 13, className: "flex-none opacity-60" })
          ] })
        ]
      }
    ),
    open && /* @__PURE__ */ jsxs(
      "div",
      {
        role: "menu",
        className: "menu-panel absolute bottom-full left-0 z-50 mb-2 w-60",
        children: [
          /* @__PURE__ */ jsxs("div", { className: "border-b border-[var(--color-border-light)] px-2.5 pt-1.5 pb-2", children: [
            /* @__PURE__ */ jsx("p", { className: "truncate text-sm font-semibold text-[var(--color-text-main)]", children: auth.user.name }),
            /* @__PURE__ */ jsx("p", { className: "truncate text-xs text-[var(--color-text-muted)]", children: auth.user.email })
          ] }),
          /* @__PURE__ */ jsxs("div", { className: "pt-1", children: [
            /* @__PURE__ */ jsxs(Link, { href: "/profile", className: "menu-item", onClick: () => setOpen(false), role: "menuitem", children: [
              /* @__PURE__ */ jsx(Icon, { name: "user", size: 16, weight: "regular" }),
              "Profile & security"
            ] }),
            /* @__PURE__ */ jsxs(Link, { href: "/billing", className: "menu-item", onClick: () => setOpen(false), role: "menuitem", children: [
              /* @__PURE__ */ jsx(Icon, { name: "wallet", size: 16, weight: "regular" }),
              "Subscription"
            ] }),
            !auth.user.two_factor_enabled && /* @__PURE__ */ jsxs(
              Link,
              {
                href: "/profile",
                className: "menu-item text-[var(--color-warning)]",
                onClick: () => setOpen(false),
                role: "menuitem",
                children: [
                  /* @__PURE__ */ jsx(Icon, { name: "shield-check", size: 16, weight: "regular" }),
                  "Turn on two-factor"
                ]
              }
            ),
            /* @__PURE__ */ jsxs(
              "button",
              {
                type: "button",
                onClick: signOut,
                className: "menu-item text-[var(--color-danger-text)]",
                role: "menuitem",
                children: [
                  /* @__PURE__ */ jsx(Icon, { name: "sign-out", size: 16, weight: "regular" }),
                  "Sign out"
                ]
              }
            )
          ] })
        ]
      }
    )
  ] });
}
const COOKIE = "prism_rail";
const COLLAPSED = "collapsed";
function useRail() {
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof document === "undefined") {
      return false;
    }
    return document.documentElement.classList.contains("rail-collapsed");
  });
  useEffect(() => {
    const stored = getCookie(COOKIE) === COLLAPSED;
    if (stored !== collapsed) {
      document.documentElement.classList.toggle("rail-collapsed", collapsed);
    }
  }, [collapsed]);
  const toggle = useCallback(() => {
    setCollapsed((was) => {
      const next = !was;
      document.documentElement.classList.toggle("rail-collapsed", next);
      setCookie(COOKIE, next ? COLLAPSED : "open");
      return next;
    });
  }, []);
  return { collapsed, toggle };
}
const PINS_KEY = "prism.nav.pins";
function usePins() {
  const [pins, setPins] = useState(() => {
    if (typeof window === "undefined") {
      return [];
    }
    try {
      const raw = window.localStorage.getItem(PINS_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.filter((v) => typeof v === "string") : [];
    } catch {
      return [];
    }
  });
  const toggle = useCallback((key) => {
    setPins((current) => {
      const next = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
      try {
        window.localStorage.setItem(PINS_KEY, JSON.stringify(next));
      } catch {
      }
      return next;
    });
  }, []);
  return { pins, toggle };
}
function Sidebar({ url, mobileOpen, onCloseMobile }) {
  const { app, nav } = useShared();
  const { collapsed, toggle } = useRail();
  const { pins, toggle: togglePin } = usePins();
  const isActive = useCallback((item) => pathMatches(url, item.match), [url]);
  const activeSections = useMemo(
    () => new Set(nav.filter((s) => s.items.some(isActive)).map((s) => s.key)),
    [nav, isActive]
  );
  const [open, setOpen] = useState(activeSections);
  useEffect(() => {
    setOpen((current) => {
      const next = new Set(current);
      let changed = false;
      for (const key of activeSections) {
        if (!next.has(key)) {
          next.add(key);
          changed = true;
        }
      }
      return changed ? next : current;
    });
  }, [activeSections]);
  const toggleSection = (key) => setOpen((current) => {
    const next = new Set(current);
    next.has(key) ? next.delete(key) : next.add(key);
    return next;
  });
  const flat = nav.filter((section) => section.flat);
  const grouped = nav.filter((section) => !section.flat);
  const pinnedItems = useMemo(() => {
    const byKey = /* @__PURE__ */ new Map();
    for (const section of nav) {
      for (const item of section.items) {
        byKey.set(item.key, item);
      }
    }
    return pins.map((key) => byKey.get(key)).filter((item) => item !== void 0);
  }, [nav, pins]);
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    mobileOpen && /* @__PURE__ */ jsx(
      "div",
      {
        className: "fixed inset-0 z-40 bg-[rgba(13,27,42,0.55)] md:hidden",
        onClick: onCloseMobile,
        "aria-hidden": true
      }
    ),
    /* @__PURE__ */ jsxs(
      "aside",
      {
        className: cn(
          "rail fixed inset-y-0 left-0 z-50 flex flex-col",
          "transition-transform duration-200 md:translate-x-0",
          mobileOpen ? "translate-x-0" : "-translate-x-full"
        ),
        "aria-label": "Main navigation",
        children: [
          /* @__PURE__ */ jsxs(
            "div",
            {
              className: cn(
                "flex h-16 flex-none items-center gap-3 border-b border-white/15 px-4",
                collapsed && "justify-center px-2"
              ),
              children: [
                !collapsed && /* @__PURE__ */ jsxs(Link, { href: "/dashboard", className: "flex min-w-0 flex-1 items-center gap-2.5", children: [
                  /* @__PURE__ */ jsx("span", { className: "grid size-8 flex-none place-items-center rounded-[10px] bg-[var(--color-brand)] text-[var(--color-ink)]", children: /* @__PURE__ */ jsx(Icon, { name: "sparkle", size: 18, weight: "fill" }) }),
                  /* @__PURE__ */ jsx("span", { className: "truncate font-[family-name:var(--font-heading)] text-lg font-bold", children: app.name })
                ] }),
                /* @__PURE__ */ jsx(
                  "button",
                  {
                    type: "button",
                    onClick: toggle,
                    className: "rail-action hidden md:inline-flex",
                    "aria-label": collapsed ? "Expand sidebar" : "Collapse sidebar",
                    title: collapsed ? "Expand" : "Collapse",
                    children: /* @__PURE__ */ jsx(Icon, { name: collapsed ? "caret-right" : "caret-left", size: 16 })
                  }
                ),
                /* @__PURE__ */ jsx(
                  "button",
                  {
                    type: "button",
                    onClick: onCloseMobile,
                    className: "rail-action md:hidden",
                    "aria-label": "Close menu",
                    children: /* @__PURE__ */ jsx(Icon, { name: "x", size: 16 })
                  }
                )
              ]
            }
          ),
          /* @__PURE__ */ jsxs("nav", { className: "no-scrollbar flex-1 space-y-1 overflow-y-auto px-3 py-5", children: [
            flat.map(
              (section) => section.items.map((item) => /* @__PURE__ */ jsx(
                NavLink,
                {
                  item,
                  active: isActive(item),
                  collapsed,
                  pinned: pins.includes(item.key),
                  onTogglePin: togglePin
                },
                item.key
              ))
            ),
            pinnedItems.length > 0 && /* @__PURE__ */ jsxs("div", { className: "!mt-4 space-y-1 border-t border-white/10 pt-3", children: [
              !collapsed && /* @__PURE__ */ jsx("p", { className: "px-2 pb-1 text-[0.625rem] font-semibold tracking-[0.12em] text-white/35 uppercase", children: "Pinned" }),
              pinnedItems.map((item) => /* @__PURE__ */ jsx(
                NavLink,
                {
                  item,
                  active: isActive(item),
                  collapsed,
                  pinned: true,
                  onTogglePin: togglePin
                },
                `pin-${item.key}`
              ))
            ] }),
            /* @__PURE__ */ jsx("div", { className: "!mt-4 space-y-1 border-t border-white/10 pt-3", children: grouped.map((section) => /* @__PURE__ */ jsx(
              NavGroup,
              {
                section,
                collapsed,
                open: open.has(section.key),
                holdsActive: activeSections.has(section.key),
                isActive,
                onToggle: () => toggleSection(section.key),
                pins,
                onTogglePin: togglePin
              },
              section.key
            )) })
          ] }),
          /* @__PURE__ */ jsx("div", { className: "flex-none border-t border-white/15 p-3", children: /* @__PURE__ */ jsx(ProfileMenu, { collapsed }) })
        ]
      }
    )
  ] });
}
function NavGroup({ section, collapsed, open, holdsActive, isActive, onToggle, pins, onTogglePin }) {
  const trigger = useRef(null);
  const [flyout, setFlyout] = useState(null);
  const showFlyout = () => {
    if (!collapsed || !trigger.current) {
      return;
    }
    const box = trigger.current.getBoundingClientRect();
    setFlyout({
      top: Math.min(box.top, window.innerHeight - 320),
      left: box.right + 8
    });
  };
  return /* @__PURE__ */ jsxs(
    "div",
    {
      className: "relative",
      onMouseEnter: showFlyout,
      onMouseLeave: () => setFlyout(null),
      children: [
        /* @__PURE__ */ jsxs(
          "button",
          {
            ref: trigger,
            type: "button",
            onClick: collapsed ? void 0 : onToggle,
            className: cn("nav-row", holdsActive && "holds-active", collapsed && "justify-center"),
            "aria-expanded": collapsed ? void 0 : open,
            title: collapsed ? section.label ?? void 0 : void 0,
            children: [
              /* @__PURE__ */ jsx("span", { className: "nav-icon", children: /* @__PURE__ */ jsx(Icon, { name: section.icon, size: 18 }) }),
              !collapsed && /* @__PURE__ */ jsxs(Fragment, { children: [
                /* @__PURE__ */ jsx("span", { className: "nav-label", children: section.label }),
                /* @__PURE__ */ jsx(
                  Icon,
                  {
                    name: "caret-right",
                    size: 13,
                    className: cn("flex-none transition-transform duration-150", open && "rotate-90")
                  }
                )
              ] })
            ]
          }
        ),
        !collapsed && open && /* @__PURE__ */ jsx("div", { className: "mt-0.5 space-y-0.5", children: section.items.map((item) => /* @__PURE__ */ jsx(
          NavLink,
          {
            item,
            active: isActive(item),
            collapsed: false,
            child: true,
            pinned: pins.includes(item.key),
            onTogglePin
          },
          item.key
        )) }),
        collapsed && flyout && createPortal(
          /* @__PURE__ */ jsxs(
            "div",
            {
              className: "nav-flyout no-scrollbar",
              style: { top: flyout.top, left: flyout.left },
              onMouseEnter: showFlyout,
              onMouseLeave: () => setFlyout(null),
              children: [
                /* @__PURE__ */ jsx("p", { className: "px-2 pt-1 pb-2 text-[0.625rem] font-semibold tracking-[0.12em] text-white/40 uppercase", children: section.label }),
                section.items.map((item) => /* @__PURE__ */ jsx(NavLink, { item, active: isActive(item), collapsed: false }, item.key))
              ]
            }
          ),
          document.body
        )
      ]
    }
  );
}
const ICONS = { success: "check", error: "x", warning: "warning" };
const TONES = {
  success: { background: "var(--color-success-subtle)", color: "var(--color-success)" },
  error: { background: "var(--color-danger-subtle)", color: "var(--color-danger-text)" },
  warning: { background: "var(--color-warning-subtle)", color: "var(--color-warning)" }
};
function Toasts() {
  const { flash } = useShared();
  const [toasts, setToasts] = useState([]);
  useEffect(() => {
    const incoming = [];
    let seed = Date.now();
    for (const tone of ["success", "error", "warning"]) {
      const message = flash[tone];
      if (message) {
        incoming.push({ id: seed++, tone, message });
      }
    }
    if (incoming.length === 0) {
      return;
    }
    setToasts((current) => [...current, ...incoming]);
    const timers = incoming.map(
      (toast) => window.setTimeout(
        () => setToasts((current) => current.filter((t) => t.id !== toast.id)),
        toast.tone === "error" ? 8e3 : 4e3
      )
    );
    return () => timers.forEach(window.clearTimeout);
  }, [flash]);
  if (toasts.length === 0) {
    return null;
  }
  return /* @__PURE__ */ jsx(
    "div",
    {
      className: "pointer-events-none fixed top-4 right-4 z-[100] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2",
      role: "status",
      "aria-live": "polite",
      children: toasts.map((toast) => /* @__PURE__ */ jsxs(
        "div",
        {
          className: "pointer-events-auto flex items-start gap-2.5 rounded-2xl px-4 py-3 text-sm font-medium shadow-[var(--shadow-lg)]",
          style: TONES[toast.tone],
          children: [
            /* @__PURE__ */ jsx(Icon, { name: ICONS[toast.tone], size: 16, weight: "bold", className: "mt-0.5 flex-none" }),
            /* @__PURE__ */ jsx("span", { className: "flex-1", children: toast.message }),
            /* @__PURE__ */ jsx(
              "button",
              {
                type: "button",
                onClick: () => setToasts((current) => current.filter((t) => t.id !== toast.id)),
                className: "flex-none opacity-50 hover:opacity-100",
                "aria-label": "Dismiss",
                children: /* @__PURE__ */ jsx(Icon, { name: "x", size: 14 })
              }
            )
          ]
        },
        toast.id
      ))
    }
  );
}
function BusinessSwitcher() {
  const { tenant } = useShared();
  const [open, setOpen] = useState(false);
  const container = useRef(null);
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointerDown = (event) => {
      if (!container.current?.contains(event.target)) {
        setOpen(false);
      }
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);
  if (!tenant?.business || tenant.businesses.length < 2) {
    return tenant?.business ? /* @__PURE__ */ jsxs("span", { className: "hidden items-center gap-2 text-sm font-semibold text-[var(--color-text-main)] sm:flex", children: [
      /* @__PURE__ */ jsx(Icon, { name: "buildings", size: 16, className: "text-[var(--color-text-muted)]" }),
      tenant.business.name
    ] }) : null;
  }
  const switchTo = (id) => {
    setOpen(false);
    if (id === tenant.business?.id) {
      return;
    }
    resetCacheForBusinessSwitch();
    router.post(
      "/business/switch",
      { business: id },
      {
        preserveScroll: true,
        // The shell's own props change — account, business, menu — so
        // this one navigation deliberately does re-render the layout.
        preserveState: false
      }
    );
  };
  return /* @__PURE__ */ jsxs("div", { ref: container, className: "relative", children: [
    /* @__PURE__ */ jsxs(
      "button",
      {
        type: "button",
        onClick: () => setOpen((was) => !was),
        className: "flex items-center gap-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-1.5 text-sm font-semibold text-[var(--color-text-main)] hover:border-[var(--color-border-strong)]",
        "aria-haspopup": "listbox",
        "aria-expanded": open,
        children: [
          /* @__PURE__ */ jsx(Icon, { name: "buildings", size: 16, className: "text-[var(--color-text-muted)]" }),
          /* @__PURE__ */ jsx("span", { className: "max-w-40 truncate", children: tenant.business.name }),
          /* @__PURE__ */ jsx(Icon, { name: "caret-up-down", size: 13, className: "text-[var(--color-text-muted)]" })
        ]
      }
    ),
    open && /* @__PURE__ */ jsxs("div", { role: "listbox", className: "menu-panel absolute left-0 z-50 mt-2 w-64", children: [
      /* @__PURE__ */ jsx("p", { className: "px-2.5 pt-1 pb-1.5 text-[0.625rem] font-semibold tracking-[0.12em] text-[var(--color-text-muted)] uppercase", children: "Businesses" }),
      tenant.businesses.map((business) => {
        const current = business.id === tenant.business?.id;
        return /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            role: "option",
            "aria-selected": current,
            onClick: () => switchTo(business.id),
            className: "menu-item",
            children: [
              /* @__PURE__ */ jsx("span", { className: "grid size-7 flex-none place-items-center rounded-lg bg-[var(--color-brand-subtle)] text-[0.625rem] font-bold text-[var(--color-ink)]", children: business.short_code ?? business.name.slice(0, 2).toUpperCase() }),
              /* @__PURE__ */ jsxs("span", { className: "min-w-0 flex-1", children: [
                /* @__PURE__ */ jsx("span", { className: "block truncate font-medium", children: business.name }),
                /* @__PURE__ */ jsx("span", { className: "block text-[0.6875rem] text-[var(--color-text-muted)]", children: business.currency })
              ] }),
              current && /* @__PURE__ */ jsx(Icon, { name: "check", size: 15, className: "text-[var(--color-brand-active)]" })
            ]
          },
          business.id
        );
      })
    ] })
  ] });
}
function Topbar({ onOpenMobileMenu }) {
  const { app, tenant, auth } = useShared();
  const account = tenant?.account;
  const trialDaysLeft = account?.trial_ends_at ? Math.max(0, Math.ceil((Date.parse(account.trial_ends_at) - Date.now()) / 864e5)) : null;
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs("header", { className: "topbar sticky top-0 z-30 flex items-center justify-between gap-3 px-4 sm:px-6", children: [
      /* @__PURE__ */ jsxs("div", { className: "flex min-w-0 items-center gap-3", children: [
        /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: onOpenMobileMenu,
            className: "rounded-lg p-2 text-[var(--color-text-body)] hover:bg-[var(--color-brand-subtle)] md:hidden",
            "aria-label": "Open menu",
            children: /* @__PURE__ */ jsx(Icon, { name: "list", size: 20 })
          }
        ),
        /* @__PURE__ */ jsx("span", { className: "font-[family-name:var(--font-heading)] text-base font-bold text-[var(--color-text-main)] md:hidden", children: app.name }),
        /* @__PURE__ */ jsx("div", { className: "hidden md:block", children: /* @__PURE__ */ jsx(BusinessSwitcher, {}) })
      ] }),
      /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-2", children: [
        /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            className: "hidden items-center gap-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-1.5 text-sm text-[var(--color-text-muted)] hover:border-[var(--color-border-strong)] lg:flex",
            "aria-label": "Search",
            children: [
              /* @__PURE__ */ jsx(Icon, { name: "magnifying-glass", size: 15, weight: "regular" }),
              /* @__PURE__ */ jsx("span", { children: "Search" }),
              /* @__PURE__ */ jsx("kbd", { className: "ml-6 rounded border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] px-1.5 py-0.5 font-[family-name:var(--font-mono)] text-[0.625rem]", children: "/" })
            ]
          }
        ),
        auth && !auth.user.email_verified && /* @__PURE__ */ jsxs(
          Link,
          {
            href: "/verify-email",
            className: "chip bg-[var(--color-warning-subtle)] text-[var(--color-warning)]",
            children: [
              /* @__PURE__ */ jsx(Icon, { name: "warning", size: 12, weight: "fill" }),
              "Verify email"
            ]
          }
        )
      ] })
    ] }),
    account?.status === "trialing" && trialDaysLeft !== null && /* @__PURE__ */ jsxs(Banner, { tone: "brand", children: [
      trialDaysLeft > 0 ? `${trialDaysLeft} ${trialDaysLeft === 1 ? "day" : "days"} left on your trial.` : "Your trial has ended.",
      " ",
      /* @__PURE__ */ jsx(Link, { href: "/billing", className: "font-semibold underline underline-offset-2", children: "Choose a plan" })
    ] }),
    account?.status === "past_due" && /* @__PURE__ */ jsxs(Banner, { tone: "warning", children: [
      "A payment did not go through. Everything keeps working —",
      " ",
      /* @__PURE__ */ jsx(Link, { href: "/billing", className: "font-semibold underline underline-offset-2", children: "update your details" }),
      " ",
      "when you can."
    ] })
  ] });
}
function Banner({ tone, children }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      className: "px-4 py-2 text-center text-[0.8125rem] sm:px-6",
      style: tone === "warning" ? { background: "var(--color-warning-subtle)", color: "var(--color-warning)" } : { background: "var(--color-brand-subtle)", color: "var(--color-ink-soft)" },
      children
    }
  );
}
function AppLayout({ children }) {
  const { url } = usePage();
  const [mobileOpen, setMobileOpen] = useState(false);
  useEffect(() => {
    setMobileOpen(false);
  }, [url]);
  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);
  return /* @__PURE__ */ jsxs("div", { className: "min-h-screen", children: [
    /* @__PURE__ */ jsx(Sidebar, { url, mobileOpen, onCloseMobile: () => setMobileOpen(false) }),
    /* @__PURE__ */ jsxs(
      "div",
      {
        className: "flex min-h-screen min-w-0 flex-col transition-[padding] duration-200 md:pl-[var(--sidebar-width)]",
        children: [
          /* @__PURE__ */ jsx(Topbar, { onOpenMobileMenu: () => setMobileOpen(true) }),
          /* @__PURE__ */ jsx("main", { className: "min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8", children })
        ]
      }
    ),
    /* @__PURE__ */ jsx(Toasts, {})
  ] });
}
const pages = /* @__PURE__ */ Object.assign({ "./pages/Dashboard.tsx": __vite_glob_0_0, "./pages/Error.tsx": __vite_glob_0_1, "./pages/auth/ConfirmPassword.tsx": __vite_glob_0_2, "./pages/auth/ForgotPassword.tsx": __vite_glob_0_3, "./pages/auth/Login.tsx": __vite_glob_0_4, "./pages/auth/Register.tsx": __vite_glob_0_5, "./pages/auth/ResetPassword.tsx": __vite_glob_0_6, "./pages/auth/TwoFactorChallenge.tsx": __vite_glob_0_7, "./pages/auth/VerifyEmail.tsx": __vite_glob_0_8, "./pages/billing/Locked.tsx": __vite_glob_0_9, "./pages/billing/Show.tsx": __vite_glob_0_10, "./pages/modules/Roadmap.tsx": __vite_glob_0_11, "./pages/modules/Soon.tsx": __vite_glob_0_12, "./pages/profile/Show.tsx": __vite_glob_0_13 });
createServer(
  (page) => createInertiaApp({
    page,
    render: renderToString,
    title: (title) => title ? `${title} · Prism` : "Prism",
    resolve: (name) => {
      const module = pages[`./pages/${name}.tsx`];
      if (!module) {
        throw new Error(`Inertia page not found: ${name}`);
      }
      if (!("layout" in module.default)) {
        module.default.layout = (pageElement) => /* @__PURE__ */ jsx(AppLayout, { children: pageElement });
      }
      return module.default;
    },
    setup: ({ App, props }) => /* @__PURE__ */ jsx(
      QueryClientProvider,
      {
        client: new QueryClient({ defaultOptions: { queries: { retry: false } } }),
        children: /* @__PURE__ */ jsx(App, { ...props })
      }
    )
  })
);
