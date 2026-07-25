import { serializeSignedCookie } from "better-call";
import { z } from "zod";
import { auth } from "../../lib/auth.js";
const ROBOMAP_API_URL = (process.env.ROBOMAP_API_URL || "https://api.robomap.ai").replace(/\/$/, "");
const bodySchema = z.object({
    access_token: z.string().min(1),
    returnUrl: z.string().optional(),
});
function sanitizeReturnUrl(raw) {
    if (!raw)
        return "/";
    if (raw.startsWith("/") && !raw.startsWith("//") && !raw.startsWith("/auth/")) {
        return raw;
    }
    return "/";
}
export async function robomapSso(request, reply) {
    try {
        const parsed = bodySchema.safeParse(request.body);
        if (!parsed.success) {
            return reply.status(400).send({ error: "access_token is required" });
        }
        const { access_token: accessToken } = parsed.data;
        const returnUrl = sanitizeReturnUrl(parsed.data.returnUrl);
        const exchangeUrl = `${ROBOMAP_API_URL}/auth/oauth/session?access_token=${encodeURIComponent(accessToken)}`;
        const exchangeRes = await fetch(exchangeUrl, {
            method: "GET",
            headers: {
                Accept: "application/json",
                "User-Agent": "robomap-analytics-sso/1.0",
            },
        });
        if (!exchangeRes.ok) {
            return reply.status(401).send({ error: "Invalid or expired Robomap session token" });
        }
        const exchangeData = (await exchangeRes.json());
        const robomapUser = exchangeData.user;
        const email = (robomapUser?.email || "").trim().toLowerCase();
        if (!email) {
            return reply.status(401).send({ error: "Robomap user is missing an email" });
        }
        const displayName = (robomapUser?.name || "").trim() ||
            (robomapUser?.full_name || "").trim() ||
            (robomapUser?.username || "").trim() ||
            email.split("@")[0] ||
            "User";
        const ctx = await auth.$context;
        let existing = await ctx.internalAdapter.findUserByEmail(email, { includeAccounts: true });
        let user = existing?.user;
        if (!user) {
            user = await ctx.internalAdapter.createUser({
                email,
                name: displayName,
                emailVerified: true,
                image: robomapUser?.profile_image || undefined,
            });
        }
        if (!user?.id) {
            return reply.status(500).send({ error: "Failed to resolve analytics user" });
        }
        // Link/refresh a robomap account record so Google/Apple identities stay tied to the same email.
        const accounts = existing?.accounts?.length
            ? existing.accounts
            : await ctx.internalAdapter.findAccounts(user.id);
        const robomapAccountId = String(robomapUser?.id ?? email);
        const hasRobomapAccount = (accounts || []).some((account) => account.providerId === "robomap");
        if (!hasRobomapAccount) {
            await ctx.internalAdapter.createAccount({
                userId: user.id,
                providerId: "robomap",
                accountId: robomapAccountId,
            });
        }
        const session = await ctx.internalAdapter.createSession(user.id);
        if (!session) {
            return reply.status(500).send({ error: "Failed to create analytics session" });
        }
        const cookieName = ctx.authCookies.sessionToken.name;
        const cookieAttrs = {
            ...ctx.authCookies.sessionToken.attributes,
            maxAge: ctx.sessionConfig.expiresIn,
        };
        const setCookie = await serializeSignedCookie(cookieName, session.token, ctx.secret, cookieAttrs);
        reply.header("Set-Cookie", setCookie);
        return reply.send({
            user: {
                id: user.id,
                email: user.email,
                name: user.name,
            },
            returnUrl,
        });
    }
    catch (error) {
        console.error("Robomap SSO failed:", error);
        return reply.status(500).send({ error: "Failed to establish analytics session" });
    }
}
