package com.ronu.restgeneric.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

import java.util.List;

/**
 * Configuration properties for rest-generic-class Java library.
 *
 * <pre>
 * rest-generic:
 *   filtering:
 *     max-depth: 5
 *     max-conditions: 100
 *     strict-relations: true
 *   hibernate:
 *     fail-on-pagination-over-collection-fetch: true
 *     default-batch-fetch-size: 32
 *   cache:
 *     enabled: false
 *     store: redis
 *     ttl: 60
 *     cacheable-methods: [list_all, get_one]
 *     vary-headers: [Accept-Language, X-Tenant-Id]
 * </pre>
 */
@ConfigurationProperties(prefix = "rest-generic")
public class RestGenericProperties {

    private Filtering filtering = new Filtering();
    private HibernateConfig hibernate = new HibernateConfig();
    private Cache cache = new Cache();

    public Filtering getFiltering() { return filtering; }
    public void setFiltering(Filtering filtering) { this.filtering = filtering; }

    public HibernateConfig getHibernate() { return hibernate; }
    public void setHibernate(HibernateConfig hibernate) { this.hibernate = hibernate; }

    public Cache getCache() { return cache; }
    public void setCache(Cache cache) { this.cache = cache; }

    // -------------------------------------------------------------------------

    public static class Filtering {
        private int maxDepth = 5;
        private int maxConditions = 100;
        private boolean strictRelations = true;
        private List<String> allowedOperators = List.of(
                "=", "!=", "<>", "<", ">", "<=", ">=",
                "like", "not like", "ilike", "not ilike",
                "in", "not in", "between", "not between",
                "null", "not null", "exists", "not exists",
                "date", "not date", "regexp", "not regexp");

        public int getMaxDepth() { return maxDepth; }
        public void setMaxDepth(int maxDepth) { this.maxDepth = maxDepth; }

        public int getMaxConditions() { return maxConditions; }
        public void setMaxConditions(int maxConditions) { this.maxConditions = maxConditions; }

        public boolean isStrictRelations() { return strictRelations; }
        public void setStrictRelations(boolean strictRelations) { this.strictRelations = strictRelations; }

        public List<String> getAllowedOperators() { return allowedOperators; }
        public void setAllowedOperators(List<String> allowedOperators) { this.allowedOperators = allowedOperators; }
    }

    public static class HibernateConfig {
        private boolean failOnPaginationOverCollectionFetch = true;
        private int defaultBatchFetchSize = 32;

        public boolean isFailOnPaginationOverCollectionFetch() { return failOnPaginationOverCollectionFetch; }
        public void setFailOnPaginationOverCollectionFetch(boolean v) { this.failOnPaginationOverCollectionFetch = v; }

        public int getDefaultBatchFetchSize() { return defaultBatchFetchSize; }
        public void setDefaultBatchFetchSize(int defaultBatchFetchSize) { this.defaultBatchFetchSize = defaultBatchFetchSize; }
    }

    public static class Cache {
        private boolean enabled = false;
        private String store = "redis";
        private int ttl = 60;
        private List<String> cacheableMethods = List.of("list_all", "get_one");
        private List<String> varyHeaders = List.of("Accept-Language", "X-Tenant-Id");

        public boolean isEnabled() { return enabled; }
        public void setEnabled(boolean enabled) { this.enabled = enabled; }

        public String getStore() { return store; }
        public void setStore(String store) { this.store = store; }

        public int getTtl() { return ttl; }
        public void setTtl(int ttl) { this.ttl = ttl; }

        public List<String> getCacheableMethods() { return cacheableMethods; }
        public void setCacheableMethods(List<String> cacheableMethods) { this.cacheableMethods = cacheableMethods; }

        public List<String> getVaryHeaders() { return varyHeaders; }
        public void setVaryHeaders(List<String> varyHeaders) { this.varyHeaders = varyHeaders; }
    }
}
