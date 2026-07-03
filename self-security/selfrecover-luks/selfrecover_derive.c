/*
 * selfrecover_derive.c — clone C de selfrecover_derive.py, pour l'initramfs.
 *
 * Reproduit EXACTEMENT la dérivation Python :
 *     eff_salt = SHA256("{salt}:{label}")[:16]      (16 octets binaires)
 *     clé      = Argon2id(passphrase, eff_salt, t=3, m=65536 KiB, p=4, len=32)
 *
 * La passphrase est lue sur STDIN (jamais en argv → pas dans /proc, pas dans l'historique).
 * Le sel vient de --salt ou --salt-file (le fichier selfrecover_salt embarqué dans l'initrd).
 *
 * SHA256 implémenté inline → seule dépendance externe = libargon2 (initramfs minimal).
 *
 * Compil :  cc -O2 -Wall -o selfrecover_derive selfrecover_derive.c -largon2
 * Usage  :  printf '%s' "ma passphrase" | ./selfrecover_derive --salt-file /etc/selfkeyguard/selfrecover_salt --label disk --format raw
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
/* Argon2 : prototypes déclarés à la main → on lie contre le runtime libargon2.so.1,
 * sans le paquet -dev. Signatures stables de l'implémentation de référence Argon2.
 * (C'est aussi la config sur un desktop Debian standard : runtime présent, pas de headers.) */
#define ARGON2_OK 0
extern int argon2id_hash_raw(const uint32_t t_cost, const uint32_t m_cost,
        const uint32_t parallelism, const void *pwd, const size_t pwdlen,
        const void *salt, const size_t saltlen, void *hash, const size_t hashlen);
extern const char *argon2_error_message(int error_code);

static void die(const char *m) { fprintf(stderr, "selfrecover_derive: %s\n", m); exit(1); }

/* ---- SHA-256 (FIPS 180-4), implémentation autonome ---- */
typedef struct { uint32_t h[8]; uint64_t len; uint8_t buf[64]; size_t n; } sha256_ctx;

static const uint32_t K[64] = {
  0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
  0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
  0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
  0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
  0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
  0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
  0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
  0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2 };

#define ROR(x,n) (((x)>>(n))|((x)<<(32-(n))))

static void sha256_init(sha256_ctx *c) {
  c->h[0]=0x6a09e667; c->h[1]=0xbb67ae85; c->h[2]=0x3c6ef372; c->h[3]=0xa54ff53a;
  c->h[4]=0x510e527f; c->h[5]=0x9b05688c; c->h[6]=0x1f83d9ab; c->h[7]=0x5be0cd19;
  c->len=0; c->n=0;
}

static void sha256_block(sha256_ctx *c, const uint8_t *p) {
  uint32_t w[64], a,b,d,e,f,g,h,t1,t2,s0,s1,ch,maj;
  for (int i=0;i<16;i++) w[i]=(p[i*4]<<24)|(p[i*4+1]<<16)|(p[i*4+2]<<8)|p[i*4+3];
  for (int i=16;i<64;i++){
    s0=ROR(w[i-15],7)^ROR(w[i-15],18)^(w[i-15]>>3);
    s1=ROR(w[i-2],17)^ROR(w[i-2],19)^(w[i-2]>>10);
    w[i]=w[i-16]+s0+w[i-7]+s1;
  }
  a=c->h[0]; b=c->h[1]; d=c->h[2]; e=c->h[3]; f=c->h[4]; g=c->h[5]; h=c->h[6]; t2=c->h[7];
  /* note: 'd' here is c (3rd state var), 't2' holds 8th to keep names short */
  uint32_t cc=d, hh=t2; d=cc; (void)d;
  /* re-map cleanly */
  uint32_t A=c->h[0],B=c->h[1],C=c->h[2],D=c->h[3],E=c->h[4],F=c->h[5],G=c->h[6],H=c->h[7];
  for (int i=0;i<64;i++){
    s1=ROR(E,6)^ROR(E,11)^ROR(E,25); ch=(E&F)^((~E)&G); t1=H+s1+ch+K[i]+w[i];
    s0=ROR(A,2)^ROR(A,13)^ROR(A,22); maj=(A&B)^(A&C)^(B&C); t2=s0+maj;
    H=G; G=F; F=E; E=D+t1; D=C; C=B; B=A; A=t1+t2;
  }
  c->h[0]+=A; c->h[1]+=B; c->h[2]+=C; c->h[3]+=D; c->h[4]+=E; c->h[5]+=F; c->h[6]+=G; c->h[7]+=H;
  (void)a;(void)b;(void)e;(void)f;(void)g;(void)h;(void)cc;(void)hh;
}

static void sha256_update(sha256_ctx *c, const uint8_t *p, size_t n) {
  c->len += n;
  while (n) {
    size_t k = 64 - c->n; if (k>n) k=n;
    memcpy(c->buf+c->n, p, k); c->n+=k; p+=k; n-=k;
    if (c->n==64){ sha256_block(c, c->buf); c->n=0; }
  }
}

static void sha256_final(sha256_ctx *c, uint8_t out[32]) {
  uint64_t bits = c->len*8;
  uint8_t pad=0x80; sha256_update(c,&pad,1);
  uint8_t z=0; while (c->n!=56) sha256_update(c,&z,1);
  uint8_t lb[8]; for(int i=0;i<8;i++) lb[i]=(uint8_t)(bits>>(56-8*i));
  sha256_update(c,lb,8);
  for(int i=0;i<8;i++){ out[i*4]=c->h[i]>>24; out[i*4+1]=c->h[i]>>16; out[i*4+2]=c->h[i]>>8; out[i*4+3]=c->h[i]; }
}

int main(int argc, char **argv) {
  const char *salt=NULL, *salt_file=NULL, *label="disk", *fmt="hex";
  long len=32, t_cost=3, m_cost=65536, par=4;

  for (int i=1;i<argc;i++) {
    if (!strcmp(argv[i],"--salt") && i+1<argc) salt=argv[++i];
    else if (!strcmp(argv[i],"--salt-file") && i+1<argc) salt_file=argv[++i];
    else if (!strcmp(argv[i],"--label") && i+1<argc) label=argv[++i];
    else if (!strcmp(argv[i],"--len") && i+1<argc) len=atol(argv[++i]);
    else if (!strcmp(argv[i],"--format") && i+1<argc) fmt=argv[++i];
    else die("argument inconnu");
  }

  char saltbuf[4096];
  if (salt_file) {
    FILE *f=fopen(salt_file,"rb"); if(!f) die("--salt-file illisible");
    size_t n=fread(saltbuf,1,sizeof(saltbuf)-1,f); fclose(f);
    while (n>0 && (saltbuf[n-1]=='\n'||saltbuf[n-1]=='\r')) n--;
    saltbuf[n]=0; salt=saltbuf;
  }
  if (!salt) die("--salt ou --salt-file requis");
  if (len<1 || len>1024) die("--len invalide");

  /* passphrase sur stdin (toute la 1re ligne, espaces inclus pour le diceware) */
  char word[4096]; size_t wl=0; int ch;
  while (wl<sizeof(word)-1 && (ch=getchar())!=EOF && ch!='\n') word[wl++]=(char)ch;
  word[wl]=0;
  if (wl==0) die("passphrase vide (stdin)");

  /* eff_salt = SHA256(salt ":" label)[:16] */
  char pre[8192];
  int pl=snprintf(pre,sizeof(pre),"%s:%s",salt,label);
  if (pl<0 || pl>=(int)sizeof(pre)) die("salt/label trop longs");
  uint8_t digest[32]; sha256_ctx sc; sha256_init(&sc);
  sha256_update(&sc,(uint8_t*)pre,(size_t)pl); sha256_final(&sc,digest);
  uint8_t eff_salt[16]; memcpy(eff_salt,digest,16);

  /* Argon2id */
  uint8_t *out=malloc((size_t)len); if(!out) die("malloc");
  int rc=argon2id_hash_raw((uint32_t)t_cost,(uint32_t)m_cost,(uint32_t)par,
                           word,wl, eff_salt,16, out,(size_t)len);
  if (rc!=ARGON2_OK) die(argon2_error_message(rc));

  if (!strcmp(fmt,"raw")) { fwrite(out,1,(size_t)len,stdout); }
  else { for (long i=0;i<len;i++) printf("%02x",out[i]); }

  memset(word,0,sizeof(word)); memset(out,0,(size_t)len); free(out);
  return 0;
}
