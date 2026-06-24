import type { Metadata } from "next";
import Link from "next/link";
import { getPosts } from "@/lib/api";

export const metadata: Metadata = { title: "Blog" };

export default async function PostsPage({
  searchParams,
}: {
  searchParams: Promise<{ cursor?: string; category?: string }>;
}) {
  const { cursor, category } = await searchParams;

  const { data: posts, next_cursor } = await getPosts({
    cursor: cursor ?? undefined,
    category: category ?? undefined,
    per_page: 10,
  });

  return (
    <div>
      <h1 className="text-3xl font-bold mb-8">Blog</h1>

      {posts.length === 0 && (
        <p className="text-gray-500">No posts yet.</p>
      )}

      <ul className="space-y-8">
        {posts.map((post) => (
          <li key={post.slug} className="border-b border-gray-100 pb-8">
            <Link href={`/posts/${post.slug}`} className="block group">
              <h2 className="text-xl font-semibold group-hover:text-blue-600 transition-colors">
                {post.title}
              </h2>
              {post.published_at && (
                <time
                  dateTime={post.published_at}
                  className="text-sm text-gray-500 mt-1 block"
                >
                  {new Date(post.published_at).toLocaleDateString("en-US", {
                    year: "numeric",
                    month: "long",
                    day: "numeric",
                  })}
                </time>
              )}
              {post.excerpt && (
                <p className="mt-2 text-gray-700 line-clamp-2">{post.excerpt}</p>
              )}
              {post.author && (
                <p className="mt-1 text-sm text-gray-400">By {post.author}</p>
              )}
            </Link>
          </li>
        ))}
      </ul>

      <div className="flex justify-between mt-10 text-sm">
        {cursor && (
          <Link
            href="/posts"
            className="text-blue-600 hover:underline"
          >
            ← Newer posts
          </Link>
        )}
        {next_cursor && (
          <Link
            href={`/posts?cursor=${next_cursor}${category ? `&category=${category}` : ""}`}
            className="ml-auto text-blue-600 hover:underline"
          >
            Older posts →
          </Link>
        )}
      </div>
    </div>
  );
}
